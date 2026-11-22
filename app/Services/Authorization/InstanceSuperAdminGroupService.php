<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\RoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants and revokes instance-wide super-admin through a fixed user group.
 *
 * With Spatie teams on, `model_has_roles.organisation_id` is NOT NULL, so an instance
 * super-admin cannot be a direct Spatie assignment (core hardening). The group is marked
 * `is_instance=true` with a null `organisation_id` — attributes no organisation admin can
 * set through the UI — and {@see EffectivePermissionResolverService::isSuperAdmin()} reads
 * that flag, not the display name.
 */
final class InstanceSuperAdminGroupService
{
    public const GROUP_NAME = 'Instance Super Admins';

    public function __construct(
        private readonly EffectivePermissionResolverService $permissionResolver,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {}

    /**
     * Ensures the instance group exists (`is_instance`, no organisation) and carries the
     * super-admin role definition.
     */
    public function ensureGroup(): UserGroup
    {
        $group = UserGroup::query()->where('is_instance', true)->first();

        if ($group === null) {
            $group = new UserGroup;
        }

        $group->forceFill([
            'name' => self::GROUP_NAME,
            'organisation_id' => null,
            'is_instance' => true,
            'description' => 'Instance-wide super-admin membership. Not a Spatie team assignment.',
        ])->save();

        $this->permissionRegistrar->forgetCachedPermissions();

        $role = Role::findByName(RoleEnum::SuperAdmin->value, config('auth.defaults.guard', 'web'));

        if (! $group->roles()->whereKey($role->getKey())->exists()) {
            $group->roles()->attach($role->getKey());
        }

        return $group;
    }

    /**
     * Add the user to the instance super-admin group (idempotent).
     */
    public function grant(User $user): void
    {
        $group = $this->ensureGroup();
        $group->users()->syncWithoutDetaching([$user->getKey()]);
        $this->permissionResolver->forget($user);
    }

    /**
     * Remove the user from the instance super-admin group (idempotent).
     */
    public function revoke(User $user): void
    {
        $group = UserGroup::query()->where('is_instance', true)->first();

        if ($group !== null) {
            $group->users()->detach($user->getKey());
        }

        $this->permissionResolver->forget($user);
    }

    /**
     * Sync membership to match whether the user should be an instance super-admin.
     */
    public function sync(User $user, bool $shouldBeSuperAdmin): void
    {
        if ($shouldBeSuperAdmin) {
            $this->grant($user);

            return;
        }

        $this->revoke($user);
    }
}
