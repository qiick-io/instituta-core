<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Source;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use Qiick\Outbound\Webhooks\SeenWebhookIdStore;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecret;

/**
 * Push webhooks for a source (#199): GitHub-style HMAC signatures (`X-Hub-Signature-256:
 * sha256=<hex>`) checked against the source's own secret, so one leaked secret only lets
 * someone trigger syncs of that source. Delivery ids are replay-protected after signature
 * verification, and Standard Webhooks are accepted when the sender provides them.
 */
final class SourceWebhookService
{
    public function __construct(
        private readonly SeenWebhookIdStore $seenWebhookIds,
        private readonly StandardWebhookVerifier $standardWebhookVerifier,
    ) {}

    /**
     * Sets a new secret and returns it in clear, once; the database keeps it encrypted.
     */
    public function rotateSecret(Source $source): string
    {
        $secret = Str::random(48);
        $source->forceFill(['webhook_secret' => $secret])->save();

        return $secret;
    }

    public function isSignatureValid(Source $source, string $payload, ?string $signatureHeader): bool
    {
        $secret = $source->webhook_secret;

        if ($secret === null || $secret === '' || $signatureHeader === null || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $signatureHeader);
    }

    /**
     * The delivery id sent by the git host, or null when none of the supported headers is present.
     */
    public function deliveryId(?string $githubDeliveryId, ?string $giteaDeliveryId, ?string $forgejoDeliveryId): ?string
    {
        foreach ([$githubDeliveryId, $giteaDeliveryId, $forgejoDeliveryId] as $deliveryId) {
            if (is_string($deliveryId) && trim($deliveryId) !== '') {
                return trim($deliveryId);
            }
        }

        return null;
    }

    /**
     * Claims a delivery id for the replay window. Returns true when claimed, false when replayed.
     *
     * @throws WebhookException when the store cannot record the claim
     */
    public function claimDeliveryId(Source $source, string $deliveryId): bool
    {
        return $this->seenWebhookIds->claim(
            $source->id,
            $deliveryId,
            $this->replayTtlSeconds(),
        );
    }

    /**
     * Claims a payload hash for the replay window on the GitHub/Gitea/Forgejo HMAC path.
     * Identical signed bodies re-sent under a new delivery id are treated as duplicates.
     *
     * @throws WebhookException when the store cannot record the claim
     */
    public function claimPayloadHash(Source $source, string $payload): bool
    {
        return $this->seenWebhookIds->claim(
            $source->id.':payload',
            hash('sha256', $payload),
            $this->replayTtlSeconds(),
        );
    }

    public function releaseDeliveryId(Source $source, string $deliveryId): void
    {
        $this->releaseClaim($source->id, $deliveryId);
    }

    public function releasePayloadHash(Source $source, string $payload): void
    {
        $this->releaseClaim($source->id.':payload', hash('sha256', $payload));
    }

    /**
     * Best-effort release for claim stores that cannot be rolled back through SeenWebhookIdStore v0.1.0.
     */
    private function releaseClaim(string $scope, string $webhookId): void
    {
        $store = $this->seenWebhookIds;

        if (method_exists($store, 'release')) {
            $store->release($scope, $webhookId);

            return;
        }

        app(Repository::class)->forget('outbound:whid:'.hash('sha256', $scope."\0".$webhookId));
    }

    private function replayTtlSeconds(): int
    {
        return max(1, (int) config('instituta.webhooks.replay_ttl_seconds', 86400));
    }

    public function hasStandardWebhookHeaders(?string $webhookId, ?string $webhookTimestamp, ?string $webhookSignature): bool
    {
        return is_string($webhookId) && $webhookId !== ''
            && is_string($webhookTimestamp) && $webhookTimestamp !== ''
            && is_string($webhookSignature) && $webhookSignature !== '';
    }

    /**
     * @throws WebhookException when verification fails or the replay store fails closed
     */
    public function verifyStandardWebhook(Source $source, string $payload, string $webhookId, string $webhookTimestamp, string $webhookSignature): void
    {
        $scope = $source->id;
        if ($scope === '') {
            throw new WebhookException('Webhook scope is missing.');
        }

        $this->standardWebhookVerifier->verify(
            $payload,
            $webhookId,
            $webhookTimestamp,
            $webhookSignature,
            [$this->standardWebhookSecret($source)],
            now()->toImmutable(),
            $scope,
        );
    }

    /**
     * Standard Webhooks use a derived key so a captured Standard signature cannot be replayed
     * through the GitHub HMAC path, which verifies over the raw request body with the source secret.
     *
     * @throws WebhookException when the source secret cannot be represented as a webhook secret
     */
    public function standardWebhookSecret(Source $source): WebhookSecret
    {
        $secret = $source->webhook_secret;

        if (! is_string($secret) || $secret === '') {
            throw new WebhookException('Webhook secret is missing.');
        }

        $derivedKey = hash_hmac('sha256', $secret, 'instituta-standard-webhooks-v1', true);

        return WebhookSecret::parse('whsec_'.base64_encode($derivedKey));
    }
}
