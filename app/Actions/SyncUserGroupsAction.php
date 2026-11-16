<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

/**
 * Replaces the user groups of a user and logs a `groups_synced` activity (memberships are not
 * model attributes, so the model log misses them).
 *
 * Copied from externa-core (UserController::syncRolesAndGroups).
 */
class SyncUserGroupsAction
{
    /**
     * @param  list<int>  $groupIds
     */
    public function execute(User $user, array $groupIds): void
    {
        $user->groups()->sync($groupIds);
        // Group roles count for the user's permissions: drop the stale loaded relation.
        $user->unsetRelation('groups');

        activity()
            ->performedOn($user)
            ->event('groups_synced')
            ->withProperties(['group_ids' => $groupIds])
            ->log('User groups synced');
    }
}
