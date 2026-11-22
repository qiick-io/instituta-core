<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSourceJob;
use App\Models\Source;
use App\Services\SourceWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Qiick\Outbound\Webhooks\WebhookException;

/**
 * Receives push webhooks and queues a sync of that source (#199). No API key: the source
 * secret authenticates the request. Standard Webhooks are preferred when present; otherwise a
 * GitHub/Gitea/Forgejo HMAC signature plus delivery id is required. Delivery ids are claimed
 * only after authentication succeeds, so a bad signature never burns a replay id.
 */
final class SourceWebhookController extends Controller
{
    public function __construct(
        private readonly SourceWebhookService $webhooks,
    ) {}

    public function __invoke(Request $request, string $source): JsonResponse
    {
        // Native UUID columns reject non-UUID binds on PostgreSQL; unknown shapes are 404.
        abort_unless(Str::isUuid($source), 404);
        $model = Source::query()->find($source);
        abort_if($model === null || $model->webhook_secret === null, 404);

        $payload = $request->getContent();

        if ($this->webhooks->hasStandardWebhookHeaders(
            $request->header('webhook-id'),
            $request->header('webhook-timestamp'),
            $request->header('webhook-signature'),
        )) {
            return $this->handleStandardWebhook($request, $model, $payload);
        }

        return $this->handleHmacWebhook($request, $model, $payload);
    }

    private function handleStandardWebhook(Request $request, Source $source, string $payload): JsonResponse
    {
        try {
            $this->webhooks->verifyStandardWebhook(
                $source,
                $payload,
                (string) $request->header('webhook-id'),
                (string) $request->header('webhook-timestamp'),
                (string) $request->header('webhook-signature'),
            );
        } catch (WebhookException $exception) {
            if ($this->isReplayStoreFailure($exception)) {
                return response()->json(['message' => 'Webhook replay store unavailable.'], 503);
            }

            if ($this->isDuplicateWebhookException($exception)) {
                return response()->json(['status' => 'duplicate']);
            }

            abort(401);
        }

        if ($this->isPingEvent($request)) {
            return response()->json(['message' => 'pong']);
        }

        try {
            SyncSourceJob::dispatch($source->id, isForced: false);
        } catch (\Throwable) {
            $this->webhooks->releaseDeliveryId($source, (string) $request->header('webhook-id'));

            return response()->json(['message' => 'Webhook dispatch failed.'], 503);
        }

        return response()->json(['message' => 'Sync queued.'], 202);
    }

    private function handleHmacWebhook(Request $request, Source $source, string $payload): JsonResponse
    {
        $deliveryId = $this->webhooks->deliveryId(
            $request->header('X-GitHub-Delivery'),
            $request->header('X-Gitea-Delivery'),
            $request->header('X-Forgejo-Delivery'),
        );

        if ($deliveryId === null) {
            return response()->json(['message' => 'Missing webhook delivery id.'], 400);
        }

        abort_unless($this->webhooks->isSignatureValid($source, $payload, $request->header('X-Hub-Signature-256')), 401);

        try {
            $payloadClaimed = $this->webhooks->claimPayloadHash($source, $payload);
            $deliveryClaimed = $payloadClaimed && $this->webhooks->claimDeliveryId($source, $deliveryId);
        } catch (WebhookException) {
            return response()->json(['message' => 'Webhook replay store unavailable.'], 503);
        }

        if (! $payloadClaimed || ! $deliveryClaimed) {
            return response()->json(['status' => 'duplicate']);
        }

        if ($this->isPingEvent($request)) {
            return response()->json(['message' => 'pong']);
        }

        try {
            SyncSourceJob::dispatch($source->id, isForced: false);
        } catch (\Throwable $exception) {
            $this->webhooks->releaseDeliveryId($source, $deliveryId);
            $this->webhooks->releasePayloadHash($source, $payload);

            return response()->json(['message' => 'Webhook dispatch failed.'], 503);
        }

        return response()->json(['message' => 'Sync queued.'], 202);
    }

    private function isPingEvent(Request $request): bool
    {
        return $request->header('X-GitHub-Event') === 'ping'
            || $request->header('X-Gitea-Event') === 'ping'
            || $request->header('X-Forgejo-Event') === 'ping'
            || $request->header('webhook-event') === 'ping';
    }

    /**
     * laravel-outbound v0.1.0 exposes only WebhookException without typed codes/subclasses;
     * store failures wrap the underlying storage throwable as previous.
     */
    private function isReplayStoreFailure(WebhookException $exception): bool
    {
        return $exception->getPrevious() !== null;
    }

    /**
     * laravel-outbound v0.1.0 exposes only WebhookException without typed codes/subclasses.
     */
    private function isDuplicateWebhookException(WebhookException $exception): bool
    {
        return $exception->getMessage() === 'Webhook id has already been processed.';
    }
}
