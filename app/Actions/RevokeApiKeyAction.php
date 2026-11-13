<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ApiKey;

/**
 * Revokes an API key (idempotent when already revoked).
 */
final class RevokeApiKeyAction
{
    public function execute(ApiKey $key): ApiKey
    {
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now()])->save();

            $key->loadMissing('principal.user');
            activity('keys')
                ->performedOn($key)
                ->causedBy($key->principal?->user)
                ->event('api_key.revoked')
                ->withProperties([
                    'organisation_id' => $key->principal?->organisation_id,
                    'principal_id' => $key->principal_id,
                ])
                ->log('api_key.revoked');
        }

        return $key;
    }
}
