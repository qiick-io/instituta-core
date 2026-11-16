<?php

declare(strict_types=1);

use App\Enums\ProposalActionEnum;
use App\Enums\ProposalMaterialisationStatusEnum;
use App\Enums\ProposalStatusEnum;
use App\Enums\SourceProviderEnum;
use App\Enums\SyncStatusEnum;
use App\Jobs\SyncSourceJob;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Source;
use App\Models\VaultVersion;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncScheduleService;
use App\Services\SourceSyncService;
use App\Services\SourceWebhookService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * The index stays fresh without a person running `instituta:sync` (#199).
 */
function fakeLocalCommit(string $commit): void
{
    Process::fake(function (PendingProcess $process) use ($commit) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, 'rev-parse') ? Process::result(output: $commit."\n") : Process::result();
    });
}

/**
 * A local source on a temporary copy of the native fixture that looks like a git checkout.
 */
function gitLikeLocalSource(Organisation $organisation): Source
{
    $path = storage_path('framework/testing/autosync-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $path);
    File::ensureDirectoryExists($path.'/.git');

    return app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Auto sync fixture',
        provider: SourceProviderEnum::Local,
        localPath: $path,
        ref: null,
        path: null,
        isShared: true,
    );
}

afterEach(function (): void {
    foreach (File::glob(storage_path('framework/testing/autosync-*')) as $directory) {
        File::deleteDirectory($directory);
    }
});

it('gives remote sources the default sync interval and local ones none, unless told otherwise', function (): void {
    $organisation = Organisation::factory()->create();
    $registry = app(SourceRegistryService::class);

    $github = $registry->add($organisation, 'Remote', SourceProviderEnum::Github, remoteUrl: 'https://github.com/example/brain');
    $local = $registry->add($organisation, 'Folder', SourceProviderEnum::Local, localPath: base_path('tests/Unit/Brain/Fixtures/native'));
    $remoteOff = $registry->add($organisation, 'Remote off', SourceProviderEnum::Git, remoteUrl: 'https://example.com/brain.git', syncIntervalMinutes: 0);

    expect($github->sync_interval_minutes)->toBe(SourceRegistryService::DEFAULT_SYNC_INTERVAL_MINUTES)
        ->and($local->sync_interval_minutes)->toBeNull()
        ->and($remoteOff->sync_interval_minutes)->toBeNull();
});

it('finds the sources whose interval has passed and queues them unforced', function (): void {
    Queue::fake();
    $this->travelTo(now()->startOfMinute());

    $neverSynced = Source::factory()->create(['sync_interval_minutes' => 15, 'last_synced_at' => null]);
    $stale = Source::factory()->create(['sync_interval_minutes' => 15, 'last_synced_at' => now()->subMinutes(20), 'sync_status' => SyncStatusEnum::Failed]);
    Source::factory()->create(['sync_interval_minutes' => 15, 'last_synced_at' => now()->subMinutes(5)]);
    Source::factory()->create(['sync_interval_minutes' => 15, 'last_synced_at' => now()->subMinutes(20), 'sync_status' => SyncStatusEnum::Syncing]);
    Source::factory()->create(['sync_interval_minutes' => null, 'last_synced_at' => null]);

    expect(app(SourceSyncScheduleService::class)->dueSources(now())->pluck('id')->sort()->values()->all())
        ->toBe(collect([$neverSynced->id, $stale->id])->sort()->values()->all());

    $this->artisan('instituta:sync-due')->expectsOutputToContain('Queued 2 due source sync(s).')->assertSuccessful();

    Queue::assertPushed(SyncSourceJob::class, 2);
    Queue::assertPushed(SyncSourceJob::class, fn (SyncSourceJob $job): bool => $job->sourceId === $stale->id && ! $job->isForced);
});

it('stops after the fetch when an unforced sync finds the same commit', function (): void {
    $source = gitLikeLocalSource(Organisation::factory()->create());
    $sync = app(SourceSyncService::class);

    fakeLocalCommit(str_repeat('a', 40));
    expect($sync->sync($source->fresh())['items_upserted'])->toBeGreaterThan(0);

    Item::query()->where('source_id', $source->id)->update(['title' => 'Untouched by a skipped sync']);
    $usageBefore = DB::table('quota_usages')->sum('used');

    $skipped = $sync->sync($source->fresh(), isForced: false);

    expect($skipped['skipped_unchanged'] ?? false)->toBeTrue()
        ->and($source->fresh()->sync_status)->toBe(SyncStatusEnum::Ok)
        ->and(Item::query()->where('source_id', $source->id)->where('title', 'Untouched by a skipped sync')->exists())->toBeTrue()
        ->and(DB::table('quota_usages')->sum('used'))->toBe($usageBefore);

    fakeLocalCommit(str_repeat('b', 40));
    $resynced = $sync->sync($source->fresh(), isForced: false);

    expect($resynced['skipped_unchanged'] ?? false)->toBeFalse()
        ->and($source->fresh()->last_synced_commit)->toBe(str_repeat('b', 40))
        ->and(Item::query()->where('source_id', $source->id)->where('title', 'Untouched by a skipped sync')->exists())->toBeFalse();
});

it('queues an unforced sync for a correctly signed push webhook only', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $sign = fn (string $key): string => 'sha256='.hash_hmac('sha256', $payload, $key);
    $url = route('webhooks.sources', $source->id);

    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sign('wrong')], $payload)
        ->assertUnauthorized();
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)
        ->assertUnauthorized();
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sign($secret), 'HTTP_X_GITHUB_EVENT' => 'ping'], $payload)
        ->assertOk();
    Queue::assertNothingPushed();

    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sign($secret), 'HTTP_X_GITHUB_EVENT' => 'push'], $payload)
        ->assertAccepted();
    Queue::assertPushed(SyncSourceJob::class, fn (SyncSourceJob $job): bool => $job->sourceId === $source->id && ! $job->isForced);
});

it('answers 404 for an unknown source or one without a webhook secret', function (): void {
    $source = Source::factory()->create();

    $this->postJson(route('webhooks.sources', $source->id))->assertNotFound();
    $this->postJson(route('webhooks.sources', '01jzzzzzzzzzzzzzzzzzzzzzzz'))->assertNotFound();
});

it('creates, stores encrypted and disables the webhook secret from the CLI', function (): void {
    $source = Source::factory()->create();

    $this->artisan('instituta:source:webhook', ['source' => $source->id])
        ->expectsOutputToContain(route('webhooks.sources', $source->id))
        ->expectsOutputToContain('Secret:')
        ->assertSuccessful();

    $stored = DB::table('sources')->where('id', $source->id)->value('webhook_secret');
    expect($source->fresh()->webhook_secret)->toHaveLength(48)
        ->and($stored)->not->toBe($source->fresh()->webhook_secret);

    $this->artisan('instituta:source:webhook', ['source' => $source->id, '--disable' => true])->assertSuccessful();
    expect($source->fresh()->webhook_secret)->toBeNull();
});

it('marks a materialised proposal merged once a sync finds its change in the source', function (): void {
    $source = gitLikeLocalSource(Organisation::factory()->create());
    fakeLocalCommit(str_repeat('c', 40));
    app(SourceSyncService::class)->sync($source->fresh());

    $coding = Item::query()->where('source_id', $source->id)->where('path', 'rules/coding.md')->sole();
    $style = $coding->sections()->where('anchor', 'coding-rules/style')->sole();
    $newStyle = "## Style\n\nTabs, never spaces.";

    $makeProposal = fn (array $attributes): Proposal => Proposal::factory()->create([
        'organisation_id' => $source->organisation_id,
        'scope_id' => $source->organisation_id,
        'source_id' => $source->id,
        'status' => ProposalStatusEnum::Accepted,
        'action' => ProposalActionEnum::Update,
        'path' => 'rules/coding.md',
        'stable_key' => $coding->stable_key,
        'materialisation_status' => ProposalMaterialisationStatusEnum::LocalReview,
        ...$attributes,
    ]);
    $applied = $makeProposal(['section_anchor' => 'coding-rules/style', 'body' => $newStyle]);
    $notApplied = $makeProposal(['section_anchor' => 'coding-rules/style', 'body' => "## Style\n\nSomething else."]);

    $file = $source->local_path.'/rules/coding.md';
    File::put($file, str_replace((string) $style->markdown, $newStyle, File::get($file)));
    fakeLocalCommit(str_repeat('d', 40));
    app(SourceSyncService::class)->sync($source->fresh(), isForced: false);

    expect($applied->fresh()->materialisation_status)->toBe(ProposalMaterialisationStatusEnum::Merged)
        ->and($applied->fresh()->merged_at)->not->toBeNull()
        ->and($notApplied->fresh()->materialisation_status)->toBe(ProposalMaterialisationStatusEnum::LocalReview);
});

it('marks a frontmatter-only proposal merged once the source carries its metadata (#219)', function (): void {
    $source = gitLikeLocalSource(Organisation::factory()->create());
    fakeLocalCommit(str_repeat('e', 40));
    app(SourceSyncService::class)->sync($source->fresh());

    $proposal = Proposal::factory()->create([
        'organisation_id' => $source->organisation_id,
        'scope_id' => $source->organisation_id,
        'source_id' => $source->id,
        'status' => ProposalStatusEnum::Accepted,
        'action' => ProposalActionEnum::Update,
        'path' => 'rules/coding.md',
        'stable_key' => 'rules/coding',
        'body' => null,
        'frontmatter_changes' => ['status' => 'deprecated'],
        'materialisation_status' => ProposalMaterialisationStatusEnum::LocalReview,
    ]);

    fakeLocalCommit(str_repeat('f', 40));
    app(SourceSyncService::class)->sync($source->fresh(), isForced: false);
    expect($proposal->fresh()->materialisation_status)->toBe(ProposalMaterialisationStatusEnum::LocalReview);

    $file = $source->local_path.'/rules/coding.md';
    File::put($file, str_replace("title: Coding rules\n", "title: Coding rules\nstatus: deprecated\n", File::get($file)));
    fakeLocalCommit(str_repeat('0', 40));
    app(SourceSyncService::class)->sync($source->fresh(), isForced: false);

    expect($proposal->fresh()->materialisation_status)->toBe(ProposalMaterialisationStatusEnum::Merged);
});

it('numbers vault versions by sequence even when syncs land in the same second', function (): void {
    $this->freezeTime();
    $source = gitLikeLocalSource(Organisation::factory()->create());

    foreach (['1', '2', '3'] as $digit) {
        fakeLocalCommit(str_repeat($digit, 40));
        app(SourceSyncService::class)->sync($source->fresh());
    }

    expect(VaultVersion::query()->where('organisation_id', $source->organisation_id)->pluck('label')->sort()->values()->all())
        ->toBe(['instituta@v1', 'instituta@v2', 'instituta@v3'])
        ->and($source->fresh()->sync_status)->toBe(SyncStatusEnum::Ok);
});
