<?php

declare(strict_types=1);

use App\Enums\ProposalActionEnum;
use App\Enums\ProposalMaterialisationStatusEnum;
use App\Enums\ProposalStatusEnum;
use App\Enums\SourceProviderEnum;
use App\Enums\SyncStatusEnum;
use App\Jobs\EmbedItemSectionJob;
use App\Jobs\SyncSourceJob;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Source;
use App\Models\VaultVersion;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncScheduleService;
use App\Services\SourceSyncService;
use App\Services\SourceWebhookService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Qiick\Outbound\Testing\FakeSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\SeenWebhookIdStore;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\WebhookSecret;

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

    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sign('wrong'), 'HTTP_X_GITHUB_DELIVERY' => 'delivery-bad-signature'], $payload)
        ->assertUnauthorized();
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_DELIVERY' => 'delivery-missing-signature'], $payload)
        ->assertUnauthorized();

    $pingPayload = json_encode(['zen' => 'pong'], JSON_THROW_ON_ERROR);
    $pingSign = fn (string $key): string => 'sha256='.hash_hmac('sha256', $pingPayload, $key);
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $pingSign($secret), 'HTTP_X_GITHUB_EVENT' => 'ping', 'HTTP_X_GITHUB_DELIVERY' => 'delivery-ping'], $pingPayload)
        ->assertOk();
    Queue::assertNothingPushed();

    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sign($secret), 'HTTP_X_GITHUB_EVENT' => 'push', 'HTTP_X_GITHUB_DELIVERY' => 'delivery-push'], $payload)
        ->assertAccepted();
    Queue::assertPushed(SyncSourceJob::class, fn (SyncSourceJob $job): bool => $job->sourceId === $source->id && ! $job->isForced);
});

it('answers 404 for an unknown source or one without a webhook secret', function (): void {
    $source = Source::factory()->create();

    $this->postJson(route('webhooks.sources', $source->id))->assertNotFound();
    $this->postJson(route('webhooks.sources', '01jzzzzzzzzzzzzzzzzzzzzzzz'))->assertNotFound();
});

it('rejects a replayed delivery id without queuing another sync', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $payload, $secret);
    $url = route('webhooks.sources', $source->id);
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'HTTP_X_GITHUB_DELIVERY' => 'delivery-replay',
        'HTTP_X_GITHUB_EVENT' => 'push',
    ];

    $this->call('POST', $url, [], [], [], $headers, $payload)->assertAccepted();
    $this->call('POST', $url, [], [], [], $headers, $payload)
        ->assertOk()
        ->assertJson(['status' => 'duplicate']);

    Queue::assertPushed(SyncSourceJob::class, 1);
});

it('treats an identical signed body with a new delivery id as a duplicate on the HMAC path', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $payload, $secret);
    $url = route('webhooks.sources', $source->id);

    $this->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'HTTP_X_GITHUB_DELIVERY' => 'delivery-body-1',
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $payload)->assertAccepted();

    $this->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'HTTP_X_GITHUB_DELIVERY' => 'delivery-body-2',
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $payload)->assertOk()->assertJson(['status' => 'duplicate']);

    Queue::assertPushed(SyncSourceJob::class, 1);
});

it('releases HMAC replay claims when dispatch fails after claiming', function (): void {
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $payload, $secret);
    $url = route('webhooks.sources', $source->id);
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'HTTP_X_GITHUB_DELIVERY' => 'delivery-dispatch-fails',
        'HTTP_X_GITHUB_EVENT' => 'push',
    ];

    $store = new class implements SeenWebhookIdStore
    {
        /** @var array<string, true> */
        public array $claims = [];

        public function claim(string $scope, string $webhookId, int $ttlSeconds): bool
        {
            $key = $scope."\0".$webhookId;
            if (isset($this->claims[$key])) {
                return false;
            }
            $this->claims[$key] = true;

            return true;
        }

        public function release(string $scope, string $webhookId): void
        {
            unset($this->claims[$scope."\0".$webhookId]);
        }
    };

    app()->instance(SeenWebhookIdStore::class, $store);
    app()->forgetInstance(StandardWebhookVerifier::class);

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));
    app()->instance(Dispatcher::class, $dispatcher);

    $this->call('POST', $url, [], [], [], $headers, $payload)->assertStatus(503);

    app()->forgetInstance(Dispatcher::class);
    app()->singleton(Dispatcher::class, Illuminate\Bus\Dispatcher::class);
    Queue::fake();

    $this->call('POST', $url, [], [], [], $headers, $payload)->assertAccepted();
    Queue::assertPushed(SyncSourceJob::class, 1);
});

it('does not burn a delivery id when the signature is invalid', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $url = route('webhooks.sources', $source->id);

    $this->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'wrong-secret'),
        'HTTP_X_GITHUB_DELIVERY' => 'delivery-burn-check',
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $payload)->assertUnauthorized();

    $this->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $secret),
        'HTTP_X_GITHUB_DELIVERY' => 'delivery-burn-check',
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $payload)->assertAccepted();

    Queue::assertPushed(SyncSourceJob::class, 1);
});

it('refuses a signed webhook without a delivery id', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);

    $this->call('POST', route('webhooks.sources', $source->id), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $secret),
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $payload)->assertBadRequest();

    Queue::assertNothingPushed();
});

it('accepts a valid Gitea delivery id header', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);

    $this->call('POST', route('webhooks.sources', $source->id), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $secret),
        'HTTP_X_GITEA_DELIVERY' => 'gitea-delivery-1',
        'HTTP_X_GITEA_EVENT' => 'push',
    ], $payload)->assertAccepted();

    Queue::assertPushed(SyncSourceJob::class, 1);
});

it('accepts a valid Standard Webhook and rejects expired timestamps and replays', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $signer = new StandardWebhookSigner;
    $derivedKey = hash_hmac('sha256', 'instituta-standard-webhooks-v1', $secret, true);
    $webhookSecret = WebhookSecret::parse('whsec_'.base64_encode($derivedKey));
    $url = route('webhooks.sources', $source->id);

    $signed = $signer->sign('msg_valid_1', now()->getTimestamp(), $payload, [$webhookSecret]);
    $this->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $signed->headers['webhook-signature'],
    ], $payload)->assertAccepted();

    $expired = $signer->sign('msg_expired_1', now()->subMinutes(10)->getTimestamp(), $payload, [$webhookSecret]);
    $this->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $expired->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $expired->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $expired->headers['webhook-signature'],
    ], $payload)->assertUnauthorized();

    $this->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $signed->headers['webhook-signature'],
    ], $payload)->assertOk()->assertJson(['status' => 'duplicate']);

    Queue::assertPushed(SyncSourceJob::class, 1);
});

it('does not accept a captured Standard Webhook signature replayed through the GitHub HMAC path', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $body = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $webhookId = 'msg_capture_1';
    $timestamp = (string) now()->getTimestamp();
    $signedContent = $webhookId.'.'.$timestamp.'.'.$body;

    $derivedKey = hash_hmac('sha256', 'instituta-standard-webhooks-v1', $secret, true);
    $capturedStandardSignature = base64_encode(hash_hmac('sha256', $signedContent, $derivedKey, true));
    $replayedGitHubSignature = 'sha256='.bin2hex(base64_decode($capturedStandardSignature));

    $this->call('POST', route('webhooks.sources', $source->id), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $replayedGitHubSignature,
        'HTTP_X_GITHUB_DELIVERY' => 'delivery-captured-standard',
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $signedContent)->assertUnauthorized();

    Queue::assertNothingPushed();
});

it('scopes webhook replay claims to the source', function (): void {
    Queue::fake();
    $firstSource = Source::factory()->create();
    $secondSource = Source::factory()->create();
    $firstSecret = app(SourceWebhookService::class)->rotateSecret($firstSource);
    $secondSecret = app(SourceWebhookService::class)->rotateSecret($secondSource);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $deliveryId = 'same-delivery-id';

    $this->call('POST', route('webhooks.sources', $firstSource->id), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $firstSecret),
        'HTTP_X_GITHUB_DELIVERY' => $deliveryId,
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $payload)->assertAccepted();

    $this->call('POST', route('webhooks.sources', $secondSource->id), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $secondSecret),
        'HTTP_X_GITHUB_DELIVERY' => $deliveryId,
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $payload)->assertAccepted();

    Queue::assertPushed(SyncSourceJob::class, 2);
});

it('prefers Standard Webhooks when both header sets are present', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $signer = new StandardWebhookSigner;
    $derivedKey = hash_hmac('sha256', 'instituta-standard-webhooks-v1', $secret, true);
    $webhookSecret = WebhookSecret::parse('whsec_'.base64_encode($derivedKey));
    $signed = $signer->sign('msg_prefer_1', now()->getTimestamp(), $payload, [$webhookSecret]);

    $this->call('POST', route('webhooks.sources', $source->id), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $signed->headers['webhook-signature'],
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $payload)->assertAccepted();

    Queue::assertPushed(SyncSourceJob::class, 1);
});

it('does not burn a Standard Webhook id when the signature is invalid', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $signer = new StandardWebhookSigner;
    $derivedKey = hash_hmac('sha256', 'instituta-standard-webhooks-v1', $secret, true);
    $validSecret = WebhookSecret::parse('whsec_'.base64_encode($derivedKey));
    $invalidSecret = WebhookSecret::parse('whsec_'.base64_encode(hash_hmac('sha256', 'wrong', $secret, true)));
    $timestamp = now()->getTimestamp();

    $invalid = $signer->sign('msg_burn_1', $timestamp, $payload, [$invalidSecret]);
    $this->call('POST', route('webhooks.sources', $source->id), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $invalid->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $invalid->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $invalid->headers['webhook-signature'],
    ], $payload)->assertUnauthorized();

    $valid = $signer->sign('msg_burn_1', $timestamp, $payload, [$validSecret]);
    $this->call('POST', route('webhooks.sources', $source->id), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $valid->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $valid->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $valid->headers['webhook-signature'],
    ], $payload)->assertAccepted();

    Queue::assertPushed(SyncSourceJob::class, 1);
});

it('fails closed with 503 when the Standard Webhook replay store cannot claim an id', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $signer = new StandardWebhookSigner;
    $derivedKey = hash_hmac('sha256', 'instituta-standard-webhooks-v1', $secret, true);
    $webhookSecret = WebhookSecret::parse('whsec_'.base64_encode($derivedKey));
    $signed = $signer->sign('msg_store_503', now()->getTimestamp(), $payload, [$webhookSecret]);

    $store = new FakeSeenWebhookIdStore;
    $store->failNextWrite();
    app()->instance(SeenWebhookIdStore::class, $store);
    app()->forgetInstance(StandardWebhookVerifier::class);

    $this->call('POST', route('webhooks.sources', $source->id), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $signed->headers['webhook-signature'],
    ], $payload)->assertStatus(503);

    Queue::assertNothingPushed();
});

it('releases the Standard Webhook replay claim when dispatch fails', function (): void {
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $signer = new StandardWebhookSigner;
    $derivedKey = hash_hmac('sha256', 'instituta-standard-webhooks-v1', $secret, true);
    $webhookSecret = WebhookSecret::parse('whsec_'.base64_encode($derivedKey));
    $signed = $signer->sign('msg_dispatch_fail', now()->getTimestamp(), $payload, [$webhookSecret]);
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $signed->headers['webhook-signature'],
    ];

    $store = new class implements SeenWebhookIdStore
    {
        /** @var array<string, true> */
        public array $claims = [];

        public function claim(string $scope, string $webhookId, int $ttlSeconds): bool
        {
            $key = $scope."\0".$webhookId;
            if (isset($this->claims[$key])) {
                return false;
            }
            $this->claims[$key] = true;

            return true;
        }

        public function release(string $scope, string $webhookId): void
        {
            unset($this->claims[$scope."\0".$webhookId]);
        }
    };

    app()->instance(SeenWebhookIdStore::class, $store);
    app()->forgetInstance(StandardWebhookVerifier::class);

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));
    app()->instance(Dispatcher::class, $dispatcher);

    $this->call('POST', route('webhooks.sources', $source->id), [], [], [], $headers, $payload)->assertStatus(503);

    app()->forgetInstance(Dispatcher::class);
    app()->singleton(Dispatcher::class, Illuminate\Bus\Dispatcher::class);
    Queue::fake();

    $this->call('POST', route('webhooks.sources', $source->id), [], [], [], $headers, $payload)->assertAccepted();
    Queue::assertPushed(SyncSourceJob::class, 1);
});

it('honours outbound.webhooks.allow_raw_legacy_secret for Standard Webhooks', function (): void {
    Queue::fake();
    config(['outbound.webhooks.allow_raw_legacy_secret' => false]);
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $signer = new StandardWebhookSigner;
    $derivedKey = hash_hmac('sha256', 'instituta-standard-webhooks-v1', $secret, true);
    $webhookSecret = WebhookSecret::parse('whsec_'.base64_encode($derivedKey));
    $signed = $signer->sign('msg_legacy_off', now()->getTimestamp(), $payload, [$webhookSecret]);

    $this->call('POST', route('webhooks.sources', $source->id), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $signed->headers['webhook-signature'],
    ], $payload)->assertAccepted();

    Queue::assertPushed(SyncSourceJob::class, 1);
});

it('claims Standard Webhook replay ids with the source scope, not default', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $signer = new StandardWebhookSigner;
    $derivedKey = hash_hmac('sha256', 'instituta-standard-webhooks-v1', $secret, true);
    $webhookSecret = WebhookSecret::parse('whsec_'.base64_encode($derivedKey));
    $signed = $signer->sign('msg_scope_1', now()->getTimestamp(), $payload, [$webhookSecret]);
    $claims = [];

    $store = new class($claims) implements SeenWebhookIdStore
    {
        /** @param array<int, array{scope: string, webhookId: string, ttl: int}> $claims */
        public function __construct(private array &$claims) {}

        public function claim(string $scope, string $webhookId, int $ttlSeconds): bool
        {
            $this->claims[] = ['scope' => $scope, 'webhookId' => $webhookId, 'ttl' => $ttlSeconds];

            return true;
        }
    };

    app()->instance(SeenWebhookIdStore::class, $store);
    app()->forgetInstance(StandardWebhookVerifier::class);

    $this->call('POST', route('webhooks.sources', $source->id), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $signed->headers['webhook-signature'],
    ], $payload)->assertAccepted();

    expect($claims)->toHaveCount(1)
        ->and($claims[0]['scope'])->toBe($source->id)
        ->and($claims[0]['scope'])->not->toBe('default')
        ->and($claims[0]['webhookId'])->toBe('msg_scope_1');
});

it('fails closed with 503 when the replay store cannot claim a delivery id', function (): void {
    Queue::fake();
    $source = Source::factory()->create();
    $secret = app(SourceWebhookService::class)->rotateSecret($source);
    $payload = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $store = new FakeSeenWebhookIdStore;
    $store->failNextWrite();
    app()->instance(SeenWebhookIdStore::class, $store);
    app()->forgetInstance(StandardWebhookVerifier::class);

    $this->call('POST', route('webhooks.sources', $source->id), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $secret),
        'HTTP_X_GITHUB_DELIVERY' => 'delivery-store-failure',
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $payload)->assertStatus(503);

    Queue::assertNothingPushed();
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

it('queues embeddings only for new and changed sections, and none while embeddings are off (#226)', function (): void {
    Queue::fake();
    $source = gitLikeLocalSource(Organisation::factory()->create());
    $sync = app(SourceSyncService::class);

    config(['ai.embeddings.enabled' => false]);
    fakeLocalCommit(str_repeat('a', 40));
    $sync->sync($source->fresh());
    Queue::assertNotPushed(EmbedItemSectionJob::class);

    config(['ai.embeddings.enabled' => true]);
    fakeLocalCommit(str_repeat('b', 40));
    $sync->sync($source->fresh());
    Queue::assertNotPushed(EmbedItemSectionJob::class);

    $file = $source->local_path.'/rules/coding.md';
    File::put($file, str_replace('Prefer boring code over clever code.', 'Prefer boring, obvious code.', File::get($file)));
    fakeLocalCommit(str_repeat('c', 40));
    $sync->sync($source->fresh());

    $style = ItemSection::query()->where('anchor', 'coding-rules/style')->sole();
    Queue::assertPushed(EmbedItemSectionJob::class, 1);
    Queue::assertPushed(EmbedItemSectionJob::class, fn (EmbedItemSectionJob $job): bool => $job->itemSectionId === $style->id);
});

it('queues an embedding for every section of a first sync with embeddings on (#226)', function (): void {
    Queue::fake();
    config(['ai.embeddings.enabled' => true]);
    $source = gitLikeLocalSource(Organisation::factory()->create());
    fakeLocalCommit(str_repeat('d', 40));

    app(SourceSyncService::class)->sync($source->fresh());

    Queue::assertPushed(EmbedItemSectionJob::class, ItemSection::query()->count());
});
