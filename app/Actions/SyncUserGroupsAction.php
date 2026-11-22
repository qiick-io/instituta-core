<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;

/**
 * Replaces the user groups of a user and logs a `groups_synced` activity (memberships are not
 * model attributes, so the model log misses them).
 *
 * Copied from externa-core (UserController::syncRolesAndGroups).
 */
class SyncUserGroupsAction
{
    public function __construct(
        private ReconcileGroupBrainAccessAction $reconcileBrainAccess,
        private EffectivePermissionResolverService $permissionResolver,
    ) {}

    /**
     * @param  list<int>  $groupIds
     */
    public function execute(User $user, array $groupIds): void
    {
        $user->groups()->sync($groupIds);
        $this->permissionResolver->forget($user);
        // The user's group-given brain grants follow their groups (#258).
        $this->reconcileBrainAccess->execute([$user]);

        activity()
            ->performedOn($user)
            ->event('groups_synced')
            ->withProperties(['group_ids' => $groupIds])
            ->log('User groups synced');
    }
}
