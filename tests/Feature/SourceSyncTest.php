<?php

declare(strict_types=1);

use App\Brain\Exceptions\DenylistHitException;
use App\Enums\SourceProviderEnum;
use App\Enums\SyncStatusEnum;
use App\Jobs\SyncSourceJob;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\VaultVersion;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config([
        'instituta.ingest.denylist' => ['klover', 'cybergrant', 'zerotredici'],
        'instituta.ingest.github_token' => null,
    ]);
});

it('refuses to add a source that hits the denylist', function (): void {
    $organisation = Organisation::factory()->create();

    expect(fn () => app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Acme Klover Docs',
        provider: SourceProviderEnum::Local,
        localPath: base_path('tests/Brain/Fixtures/native'),
    ))->toThrow(DenylistHitException::class);

    expect(Source::query()->count())->toBe(0);
});

it('syncs a local source and indexes fixture items', function (): void {
    $organisation = Organisation::factory()->create();
    $fixture = base_path('tests/Brain/Fixtures/native');

    $source = app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Native fixture',
        provider: SourceProviderEnum::Local,
        localPath: $fixture,
        ref: null,
        path: null,
        isShared: true,
    );

    $report = app(SourceSyncService::class)->sync($source->fresh());

    $source->refresh();

    expect($source->sync_status)->toBe(SyncStatusEnum::Ok)
        ->and($report['items_upserted'])->toBeGreaterThan(0)
        ->and(Item::query()->where('source_id', $source->id)->count())->toBeGreaterThan(0)
        ->and(Item::query()->where('source_id', $source->id)->where('path', 'rules/coding.md')->exists())->toBeTrue()
        ->and(SourceVersion::query()->where('source_id', $source->id)->where('label', 'HEAD')->exists())->toBeTrue()
        ->and(VaultVersion::query()->where('organisation_id', $organisation->id)->count())->toBe(1)
        ->and($source->last_sync_report)->toHaveKeys(['items_upserted', 'skipped', 'issues', 'commit']);
});

it('mirrors a git source with Process::fake and indexes from the mirror', function (): void {
    $organisation = Organisation::factory()->create();

    $source = app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Git fixture',
        provider: SourceProviderEnum::Git,
        remoteUrl: 'https://example.test/qiick/brain.git',
        ref: 'main',
    );

    $mirror = storage_path('app/instituta/sources/'.$source->id);
    File::ensureDirectoryExists($mirror);
    File::copyDirectory(base_path('tests/Brain/Fixtures/native'), $mirror);
    File::ensureDirectoryExists($mirror.'/.git');

    // Symfony quotes argv on macOS/Linux ("'git' 'fetch' …"), so avoid literal 'git fetch *' patterns.
    Process::fake(function (PendingProcess $process) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        if (str_contains($command, 'rev-parse')) {
            return Process::result(output: "abcdef0123456789abcdef0123456789abcdef01\n");
        }

        if (str_contains($command, 'tag')) {
            return Process::result(output: "v1.0.0\tabcdef0123456789abcdef0123456789abcdef01\n");
        }

        return Process::result();
    });

    $report = app(SourceSyncService::class)->sync($source->fresh());

    $source->refresh();

    Process::assertRan(function (PendingProcess $process): bool {
        $command = $process->command;
        $haystack = is_array($command) ? implode(' ', $command) : (string) $command;

        return str_contains($haystack, 'git') && str_contains($haystack, 'fetch');
    });

    expect($source->sync_status)->toBe(SyncStatusEnum::Ok)
        ->and($source->last_synced_commit)->toBe('abcdef0123456789abcdef0123456789abcdef01')
        ->and($report['items_upserted'])->toBeGreaterThan(0)
        ->and(Item::query()->where('source_id', $source->id)->count())->toBeGreaterThan(0)
        ->and(SourceVersion::query()->where('source_id', $source->id)->where('label', 'v1.0.0')->exists())->toBeTrue()
        ->and(VaultVersion::query()->where('organisation_id', $organisation->id)->exists())->toBeTrue();
});

it('refuses github sync when no token is configured', function (): void {
    $organisation = Organisation::factory()->create();

    $source = app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Remote knowledge',
        provider: SourceProviderEnum::Github,
        remoteUrl: 'https://github.com/example/knowledge',
    );

    Process::fake();

    $report = app(SourceSyncService::class)->sync($source->fresh());

    $source->refresh();

    expect($source->sync_status)->toBe(SyncStatusEnum::Failed)
        ->and($report['reason'])->toBe('github_token_missing')
        ->and(Item::query()->where('source_id', $source->id)->count())->toBe(0);

    Process::assertNothingRan();
});

it('adds a source and syncs via artisan commands', function (): void {
    $organisation = Organisation::factory()->create();
    $fixture = base_path('tests/Brain/Fixtures/native');

    $this->artisan('instituta:source:add', [
        'name' => 'CLI local',
        '--organisation' => $organisation->id,
        '--provider' => 'local',
        '--local-path' => $fixture,
        '--shared' => true,
    ])->assertSuccessful();

    $source = Source::query()->where('name', 'CLI local')->firstOrFail();

    $this->artisan('instituta:sync', [
        'source' => $source->id,
    ])->assertSuccessful();

    expect(Item::query()->where('source_id', $source->id)->count())->toBeGreaterThan(0);
});

it('queues SyncSourceJob when --queue is passed', function (): void {
    Queue::fake();

    $source = Source::factory()->create([
        'provider' => SourceProviderEnum::Local,
        'local_path' => base_path('tests/Brain/Fixtures/native'),
    ]);

    $this->artisan('instituta:sync', [
        'source' => $source->id,
        '--queue' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SyncSourceJob::class, fn (SyncSourceJob $job): bool => $job->sourceId === $source->id);
});
