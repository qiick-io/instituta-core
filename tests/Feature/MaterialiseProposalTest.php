<?php

declare(strict_types=1);

use App\Actions\MaterialiseProposalAction;
use App\Enums\ProposalActionEnum;
use App\Enums\ProposalMaterialisationStatusEnum;
use App\Enums\ProposalStatusEnum;
use App\Enums\SourceProviderEnum;
use App\Jobs\MaterialiseProposalJob;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Source;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

it('queues materialisation when a proposal is accepted via the API path job', function (): void {
    Queue::fake();

    $organisation = Organisation::factory()->create();
    $proposal = Proposal::factory()->create([
        'organisation_id' => $organisation->id,
        'scope_id' => $organisation->id,
        'status' => ProposalStatusEnum::Accepted,
        'materialisation_status' => ProposalMaterialisationStatusEnum::Pending,
    ]);

    MaterialiseProposalJob::dispatch($proposal->id);

    Queue::assertPushed(MaterialiseProposalJob::class, fn (MaterialiseProposalJob $job): bool => $job->proposalId === $proposal->id);
});

it('materialises a github proposal as a pushed branch and pull request', function (): void {
    config(['instituta.ingest.github_token' => 'test-token']);

    $organisation = Organisation::factory()->create();
    $source = Source::factory()->github()->create([
        'organisation_id' => $organisation->id,
        'remote_url' => 'https://github.com/example/knowledge',
        'ref' => 'main',
        'path' => null,
    ]);

    $mirror = storage_path('app/instituta/sources/'.$source->id);
    File::ensureDirectoryExists($mirror);
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $mirror);
    File::ensureDirectoryExists($mirror.'/.git');

    $proposal = Proposal::factory()->create([
        'organisation_id' => $organisation->id,
        'scope_id' => $organisation->id,
        'source_id' => $source->id,
        'status' => ProposalStatusEnum::Accepted,
        'action' => ProposalActionEnum::Create,
        'path' => 'rules/new-from-proposal.md',
        'stable_key' => 'rules/new-from-proposal',
        'title' => 'Add new rule',
        'body' => "# New rule\n\nBody stays out of audit.\n",
        'materialisation_status' => ProposalMaterialisationStatusEnum::Pending,
    ]);

    Process::fake(function (PendingProcess $process) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        if (str_contains($command, 'rev-parse')) {
            return Process::result(output: "abcdef0123456789abcdef0123456789abcdef01\n");
        }

        return Process::result();
    });

    Http::fake([
        'api.github.com/repos/example/knowledge/pulls' => Http::response([
            'html_url' => 'https://github.com/example/knowledge/pull/42',
        ], 201),
    ]);

    $updated = app(MaterialiseProposalAction::class)->execute($proposal->fresh());

    expect($updated->materialisation_status)->toBe(ProposalMaterialisationStatusEnum::Materialised)
        ->and($updated->remote_branch)->toBe('instituta/proposal-'.$proposal->id)
        ->and($updated->remote_pr_url)->toBe('https://github.com/example/knowledge/pull/42')
        ->and($updated->materialised_commit)->toBe('abcdef0123456789abcdef0123456789abcdef01')
        ->and($updated->materialisation_error)->toBeNull();

    $written = storage_path('app/instituta/proposals/'.$proposal->id.'/rules/new-from-proposal.md');
    expect(File::get($written))->toContain('Body stays out of audit');

    // Durable github mirror on disk is untouched (work copy only).
    expect(File::exists($mirror.'/rules/new-from-proposal.md'))->toBeFalse();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.github.com/repos/example/knowledge/pulls'
        && $request['head'] === 'instituta/proposal-'.$proposal->id
        && $request['base'] === 'main');

    Process::assertRan(function (PendingProcess $process): bool {
        $haystack = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($haystack, 'git') && str_contains($haystack, 'push');
    });
});

it('keeps local materialisation in a review workspace without mutating the source path', function (): void {
    $fixture = base_path('tests/Unit/Brain/Fixtures/native');
    $organisation = Organisation::factory()->create();
    $source = Source::factory()->create([
        'organisation_id' => $organisation->id,
        'provider' => SourceProviderEnum::Local,
        'local_path' => $fixture,
        'remote_url' => null,
        'path' => null,
    ]);

    $proposal = Proposal::factory()->create([
        'organisation_id' => $organisation->id,
        'scope_id' => $organisation->id,
        'source_id' => $source->id,
        'status' => ProposalStatusEnum::Accepted,
        'action' => ProposalActionEnum::Update,
        'path' => 'rules/coding.md',
        'stable_key' => 'rules/coding',
        'body' => "# Updated locally\n",
        'materialisation_status' => ProposalMaterialisationStatusEnum::Pending,
    ]);

    Process::fake(function (PendingProcess $process) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        if (str_contains($command, 'rev-parse')) {
            return Process::result(output: "localcommit0123456789abcdef0123456789abcd\n");
        }

        return Process::result();
    });

    $original = File::get($fixture.'/rules/coding.md');

    $updated = app(MaterialiseProposalAction::class)->execute($proposal->fresh());

    expect($updated->materialisation_status)->toBe(ProposalMaterialisationStatusEnum::LocalReview)
        ->and($updated->remote_pr_url)->toBeNull()
        ->and(File::get($fixture.'/rules/coding.md'))->toBe($original)
        ->and(File::get(storage_path('app/instituta/proposals/'.$proposal->id.'/rules/coding.md')))
        ->toBe("# Updated locally\n");
});

it('refuses unsafe relative paths', function (): void {
    $organisation = Organisation::factory()->create();
    $source = Source::factory()->create([
        'organisation_id' => $organisation->id,
        'provider' => SourceProviderEnum::Local,
        'local_path' => base_path('tests/Unit/Brain/Fixtures/native'),
        'path' => null,
    ]);

    $proposal = Proposal::factory()->create([
        'organisation_id' => $organisation->id,
        'scope_id' => $organisation->id,
        'source_id' => $source->id,
        'status' => ProposalStatusEnum::Accepted,
        'path' => '../escape.md',
        'materialisation_status' => ProposalMaterialisationStatusEnum::Pending,
    ]);

    Process::fake();

    expect(fn () => app(MaterialiseProposalAction::class)->execute($proposal->fresh()))
        ->toThrow(InvalidArgumentException::class);

    expect($proposal->fresh()->materialisation_status)->toBe(ProposalMaterialisationStatusEnum::Failed);
});

it('refuses github materialisation without a token', function (): void {
    config(['instituta.ingest.github_token' => null]);

    $organisation = Organisation::factory()->create();
    $source = Source::factory()->github()->create([
        'organisation_id' => $organisation->id,
        'remote_url' => 'https://github.com/example/knowledge',
        'path' => null,
    ]);

    $mirror = storage_path('app/instituta/sources/'.$source->id);
    File::ensureDirectoryExists($mirror);
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $mirror);
    File::ensureDirectoryExists($mirror.'/.git');

    $proposal = Proposal::factory()->create([
        'organisation_id' => $organisation->id,
        'scope_id' => $organisation->id,
        'source_id' => $source->id,
        'status' => ProposalStatusEnum::Accepted,
        'materialisation_status' => ProposalMaterialisationStatusEnum::Pending,
    ]);

    Process::fake();

    expect(fn () => app(MaterialiseProposalAction::class)->execute($proposal->fresh()))
        ->toThrow(RuntimeException::class);

    expect($proposal->fresh()->materialisation_status)->toBe(ProposalMaterialisationStatusEnum::Failed);
});
