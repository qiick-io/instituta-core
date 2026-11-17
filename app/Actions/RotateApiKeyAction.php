<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeyIssuerService;
use InvalidArgumentException;

/**
 * Issues a new secret for the same key row (24-hour grace for the previous hash).
 *
 * Same id, key_prefix, scopes and audit trail. The plaintext is returned once
 * and never written to the activity log.
 */
final class RotateApiKeyAction
{
    public function __construct(
        private readonly ApiKeyIssuerService $issuer,
    ) {}

    /**
     * @return array{key: ApiKey, plain: string}
     */
    public function execute(ApiKey $key, ?User $actor = null): array
    {
        if ($key->revoked_at !== null) {
            throw new InvalidArgumentException('A revoked API key cannot be rotated.');
        }

        $secret = $this->issuer->makeRotatedSecret($key);
        $graceHours = max(1, (int) config('api.key_rotation_grace_hours', 24));

        $key->forceFill([
            'previous_key_hash' => $key->key_hash,
            'previous_key_valid_until' => now()->addHours($graceHours),
            'key_hash' => $secret['hash'],
        ])->save();

        $key->loadMissing('principal.user');

        activity('api-keys')
            ->performedOn($key)
            ->causedBy($actor ?? $key->principal?->user)
            ->event('rotated')
            ->withProperties([
                'name' => $key->name,
                'scopes' => $key->scopes,
                'key_prefix' => $key->key_prefix,
                'previous_key_valid_until' => $key->previous_key_valid_until?->toIso8601String(),
            ])
            ->log('rotated');

        return ['key' => $key, 'plain' => $secret['plain']];
    }
}
