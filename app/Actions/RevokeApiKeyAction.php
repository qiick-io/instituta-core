<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ApiKey;
use App\Models\User;

/**
 * Revokes an API key immediately (idempotent when already revoked).
 *
 * Clears the rotation grace window so a previous secret cannot outlive revocation.
 */
final class RevokeApiKeyAction
{
    public function execute(ApiKey $key, ?User $actor = null): ApiKey
    {
        if ($key->revoked_at !== null) {
            return $key;
        }

        $key->forceFill([
            'revoked_at' => now(),
            'previous_key_hash' => null,
            'previous_key_valid_until' => null,
        ])->save();

        $key->loadMissing('principal.user');

        activity('api-keys')
            ->performedOn($key)
            ->causedBy($actor ?? $key->principal?->user)
            ->event('revoked')
            ->withProperties([
                'name' => $key->name,
                'scopes' => $key->scopes,
                'key_prefix' => $key->key_prefix,
                'organisation_id' => $key->principal?->organisation_id,
                'principal_id' => $key->principal_id,
            ])
            ->log('revoked');

        return $key;
    }
}
