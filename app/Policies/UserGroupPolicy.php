<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\RoleGrantService;

/**
 * Admin decisions on user groups.
 *
 * Each ability requires the same permission as its `permission:` route middleware
 * (super-admins pass through the resolver). Record abilities also keep groups out of reach
 * when they carry a role the actor could not grant ({@see RoleGrantService}): otherwise a
 * group manager could add themselves to such a group and inherit its roles. That covers the
 * super-admin role, which only super-admins grant.
 *
 * Copied from externa-core; the reach rule is Instituta's "grant only what you hold".
 */
final class UserGroupPolicy
{
    public function __construct(
        private readonly EffectivePermissionResolverService $permissionResolver,
        private readonly RoleGrantService $roleGrants,
    ) {}

    public function viewAny(?User $user): bool
    {
        return $this->hasPermission($user, PermissionEnum::CanShowGroups);
    }

    public function create(?User $user): bool
    {
        return $this->hasPermission($user, PermissionEnum::CanCreateGroups);
    }

    public function update(?User $user, UserGroup $group): bool
    {
        return $this->hasPermission($user, PermissionEnum::CanEditGroups)
            && $this->canTouch($user, $group);
    }

    public function delete(?User $user, UserGroup $group): bool
    {
        return $this->hasPermission($user, PermissionEnum::CanDeleteGroups)
            && $this->canTouch($user, $group);
    }

    public function restore(?User $user, UserGroup $group): bool
    {
        return $this->hasPermission($user, PermissionEnum::CanRestoreGroups)
            && $this->canTouch($user, $group);
    }

    public function forceDelete(?User $user, UserGroup $group): bool
    {
        return $this->hasPermission($user, PermissionEnum::CanForceDeleteGroups)
            && $this->canTouch($user, $group);
    }

    private function hasPermission(?User $user, PermissionEnum $permission): bool
    {
        return $user !== null && $this->permissionResolver->hasPermission($user, $permission->value);
    }

    /**
     * The actor could grant every role the group carries (super-admins pass).
     */
    private function canTouch(?User $user, UserGroup $group): bool
    {
        return $user !== null
            && $this->roleGrants->canGrantRoles($user, array_values($group->roles()->pluck('name')->all()));
    }
}
