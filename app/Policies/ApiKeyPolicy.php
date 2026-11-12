<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\User;

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
