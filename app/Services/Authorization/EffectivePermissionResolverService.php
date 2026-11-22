<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * Resolves effective permissions and roles: the user's direct Spatie roles and permissions
 * united with the roles of every group the user belongs to (soft-deleted groups grant nothing).
 * Super-admin, directly or through a group, holds every permission for shared Inertia props
 * and Gate checks.
 *
 * Group inheritance ported from externa-core. Relations are eager loaded once per user
 * instance (strict models forbid lazy loads); callers that change memberships in the same
 * process call {@see forget()}.
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
        $user->loadMissing(['permissions', 'roles.permissions', 'groups.roles.permissions']);

        $groupPermissionNames = $this->groupRoles($user)
            ->toBase()
            ->flatMap(static fn (Role $role) => $role->permissions)
            ->map(static fn (Permission $permission): string => (string) $permission->name);

        $permissionNames = $user->getAllPermissions()
            ->toBase()
            ->map(static fn ($permission): string => (string) $permission->name)
            ->merge($groupPermissionNames)
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
     * Role names from direct assignment and from the user's groups.
     *
     * @return list<string>
     */
    public function roleNamesFor(User $user): array
    {
        return array_values(array_unique(array_map(
            static fn (Role $role): string => $role->name,
            $this->effectiveRoles($user),
        )));
    }

    /**
     * Role ids from direct assignment and from the user's groups.
     *
     * @return list<int>
     */
    public function effectiveRoleIds(User $user): array
    {
        $roleIds = array_unique(array_map(
            static fn (Role $role): int => (int) $role->id,
            $this->effectiveRoles($user),
        ));
        sort($roleIds);

        return $roleIds;
    }

    /**
     * Whether the user holds the instance super-admin role through a group.
     *
     * With Spatie teams on, direct `model_has_roles` rows are always organisation-scoped,
     * so instance super-admin is never read from `$user->roles` (team-filtered). Groups are
     * instance-level and ignore the team filter (core hardening).
     */
    public function isSuperAdmin(User $user): bool
    {
        $user->loadMissing(['groups.roles']);

        return $user->groups
            ->flatMap(static fn (UserGroup $group) => $group->roles)
            ->contains(static fn (Role $role): bool => $role->name === RoleEnum::SuperAdmin->value);
    }

    /**
     * Drop the loaded role and group relations so the next check reads memberships again.
     */
    public function forget(User $user): void
    {
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        $user->unsetRelation('groups');
    }

    /**
     * Direct roles and group roles, sorted by name.
     *
     * @return list<Role>
     */
    private function effectiveRoles(User $user): array
    {
        $user->loadMissing(['roles', 'groups.roles']);

        /** @var Collection<int, Role> $directRoles */
        $directRoles = $user->roles;

        return array_values($directRoles
            ->concat($this->groupRoles($user))
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all());
    }

    /**
     * Roles attached to the user's (non-trashed) groups. Expects `groups.roles` to be loaded.
     *
     * @return Collection<int, Role>
     */
    private function groupRoles(User $user): Collection
    {
        return $user->groups
            ->flatMap(static fn (UserGroup $group) => $group->roles)
            ->values();
    }
}
