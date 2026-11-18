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
    public function __construct(
        private SuperAdminGuardService $superAdminGuard,
        private ReconcileGroupBrainAccessAction $reconcileBrainAccess,
    ) {}

    /**
     * @param  list<int>|null  $userIds  null keeps the current members
     * @param  list<int>|null  $roleIds  null keeps the current roles
     * @param  list<array{organisation_id: string, scope_type: string, scope_id: string, role: string, max_visibility: string|null, areas: list<string>, path_prefixes: list<string>}>|null  $brainAccess  null keeps it; members' grants follow (#258), with its limits (#334)
     *
     * @throws ValidationException under `user_ids` when no active super-admin would be left
     */
    public function execute(UserGroup $group, string $name, ?string $description, ?array $userIds, ?array $roleIds, ?array $brainAccess = null): UserGroup
    {
        return $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($group, $name, $description, $userIds, $roleIds, $brainAccess): UserGroup {
            // Members before and after the save: everyone whose group grants may change.
            $previousMembers = $group->exists ? $group->users()->get() : collect();

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

            if ($brainAccess !== null) {
                $group->brainAccess()->delete();

                foreach ($brainAccess as $access) {
                    $group->brainAccess()->create($access);
                }

                activity()
                    ->performedOn($group)
                    ->event('brain_access_synced')
                    ->withProperties(['brain_access' => $brainAccess])
                    ->log('Group brain access synced');
            }

            if ($userIds !== null || $brainAccess !== null) {
                $this->reconcileBrainAccess->execute($previousMembers->merge($group->users()->get())->unique('id'));
            }

            return $group;
        }, 'user_ids');
    }
}
