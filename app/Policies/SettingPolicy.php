<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;

/**
 * Instance settings are edited with `can-manage-settings` (synced from PermissionEnum).
 */
class SettingPolicy
{
    public function update(User $user): bool
    {
        return $user->can(PermissionEnum::CanManageSettings->value);
    }
}
