<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Services\Authorization\RoleGrantService;
use App\Services\Authorization\SuperAdminGuardService;

/**
 * Users are managed with `can-*-users`, but only users whose roles the actor could grant
 * (RoleGrantService): nobody edits or deletes a more powerful account. Nobody may delete
 * themselves, and the last active super-admin cannot be deleted.
 *
 * Copied from excubia-app. "Above" counts roles inherited through user groups too (the resolver).
 */
class UserPolicy
{
    public function __construct(
        private RoleGrantService $roleGrants,
        private SuperAdminGuardService $superAdminGuard,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionEnum::CanShowUsers->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionEnum::CanCreateUsers->value);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can(PermissionEnum::CanEditUsers->value) && $this->canTouch($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->can(PermissionEnum::CanDeleteUsers->value)
            && $this->canTouch($actor, $target)
            && ! $actor->is($target)
            && ! $this->superAdminGuard->isLastSuperAdmin($target);
    }

    /** The target holds no role the actor could not grant (super-admins pass). */
    private function canTouch(User $actor, User $target): bool
    {
        return $this->roleGrants->isAtOrBelow($actor, $target);
    }
}
