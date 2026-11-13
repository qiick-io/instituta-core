<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiKey;
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

    public function delete(User $user, ApiKey $apiKey): bool
    {
        return $this->owns($user, $apiKey);
    }

    private function owns(User $user, ApiKey $apiKey): bool
    {
        return $user->principals()
            ->whereKey($apiKey->principal_id)
            ->exists();
    }
}
