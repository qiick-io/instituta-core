<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\Principal;
use App\Models\User;

/**
 * Portal and future write surfaces for API keys: the actor must hold a principal,
 * and record-level view/delete require that principal to own the key.
 */
class ApiKeyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->principals()->exists();
    }

    public function view(User $user, ApiKey $apiKey): bool
    {
        return $this->owns($user, $apiKey);
    }

    public function create(User $user): bool
    {
        return $user->principals()->exists();
    }

    public function update(User $user, ApiKey $apiKey): bool
    {
        return $this->owns($user, $apiKey) && $apiKey->revoked_at === null;
    }

    public function delete(User $user, ApiKey $apiKey): bool
    {
        return $this->owns($user, $apiKey);
    }

    /**
     * The key belongs to one of the user's principals, or to an agent / service principal one
     * of them owns (keys created for agents on the Keys page, #201).
     */
    private function owns(User $user, ApiKey $apiKey): bool
    {
        $userPrincipalIds = $user->principals()->pluck('id');

        return $userPrincipalIds->contains($apiKey->principal_id)
            || Principal::query()
                ->whereKey($apiKey->principal_id)
                ->whereIn('owner_principal_id', $userPrincipalIds)
                ->exists();
    }
}
