<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Source;
use Illuminate\Support\Str;

/**
 * Push webhooks for a source (#199): GitHub-style HMAC signatures (`X-Hub-Signature-256:
 * sha256=<hex>`) checked against the source's own secret, so one leaked secret only lets
 * someone trigger syncs of that source.
 */
final class SourceWebhookService
{
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
}
