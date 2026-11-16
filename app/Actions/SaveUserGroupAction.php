<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\UserGroup;
use App\Services\Authorization\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a user group and, when given, replaces its members and roles. The group
 * change is logged by the model, member and role changes as `members_synced` /
 * `roles_synced` activities (pivots are not model attributes).
 *
 * Runs inside the last-super-admin guard: removing the last super-admin from a group that
 * carries the role, or the role from the group, is refused.
 *
 * Copied from externa-core (controller logic moved into an action).
 */
class SaveUserGroupAction
{
    public function __construct(private SuperAdminGuardService $superAdminGuard) {}

    /**
     * @param  list<int>|null  $userIds  null keeps the current members
     * @param  list<int>|null  $roleIds  null keeps the current roles
     *
     * @throws ValidationException under `user_ids` when no active super-admin would be left
     */
    public function execute(UserGroup $group, string $name, ?string $description, ?array $userIds, ?array $roleIds): UserGroup
    {
        return $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($group, $name, $description, $userIds, $roleIds): UserGroup {
            $group->fill(['name' => $name, 'description' => $description])->save();

            if ($userIds !== null) {
                $group->users()->sync($userIds);

                activity()
                    ->performedOn($group)
                    ->event('members_synced')
                    ->withProperties(['user_ids' => $userIds])
                    ->log('Group members synced');
            }

            if ($roleIds !== null) {
                $group->roles()->sync($roleIds);

                activity()
                    ->performedOn($group)
                    ->event('roles_synced')
                    ->withProperties(['role_ids' => $roleIds])
                    ->log('Group roles synced');
            }

            return $group;
        }, 'user_ids');
    }
}
