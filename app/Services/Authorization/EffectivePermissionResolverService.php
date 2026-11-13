<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;

/**
 * Resolves effective permissions and roles from direct Spatie assignment.
 *
 * Instituta has no user-group role inheritance yet (unlike externa-core). Super-admin
 * holds every permission for shared Inertia props and Gate checks.
 */
class EffectivePermissionResolverService
{
    /**
     * Flat list of permission names effective for the user.
     *
     * @return list<string>
     */
    public function permissionsFor(User $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return PermissionEnum::values();
        }

        // Spatie reads both relations; load them once (strict models forbid lazy loads).
        $user->loadMissing(['permissions', 'roles.permissions']);

        $permissionNames = $user->getAllPermissions()
            ->map(static fn ($permission): string => (string) $permission->name)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return array_values($permissionNames);
    }

    /**
     * Whether the user has the given permission; super-admin always passes.
     */
    public function hasPermission(User $user, string $permission): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return in_array($permission, $this->permissionsFor($user), true);
    }

    /**
     * @return list<string>
     */
    public function roleNamesFor(User $user): array
    {
        $user->loadMissing('roles');

        $roleNames = $user->getRoleNames()
            ->map(static fn (mixed $roleName): string => (string) $roleName)
            ->sort()
            ->values()
            ->all();

        return array_values($roleNames);
    }

    /**
     * Whether the user holds the super-admin role.
     */
    public function isSuperAdmin(User $user): bool
    {
        return in_array(RoleEnum::SuperAdmin->value, $this->roleNamesFor($user), true);
    }
}
