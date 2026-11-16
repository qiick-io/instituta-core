<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\UserGroup;
use App\Services\Authorization\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;

/**
 * Soft-deletes, restores or permanently deletes user groups, one by one so each change is in
 * the activity log. `restore` and `force_delete` only touch trashed groups, `delete` only
 * active ones. A trashed group grants nothing, so the change runs inside the
 * last-super-admin guard.
 *
 * Copied from externa-core (UserGroupController / UserGroupTrashController logic).
 */
class ChangeUserGroupsStateAction
{
    public function __construct(private SuperAdminGuardService $superAdminGuard) {}

    /**
     * @param  'delete'|'restore'|'force_delete'  $action
     * @param  list<int>  $groupIds
     *
     * @throws ValidationException under `ids` when no active super-admin would be left
     */
    public function execute(string $action, array $groupIds): void
    {
        $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($action, $groupIds): void {
            $groups = match ($action) {
                'delete' => UserGroup::query()->whereIn('id', $groupIds)->get(),
                'restore', 'force_delete' => UserGroup::onlyTrashed()->whereIn('id', $groupIds)->get(),
            };

            foreach ($groups as $group) {
                match ($action) {
                    'delete' => $group->delete(),
                    'restore' => $group->restore(),
                    'force_delete' => $group->forceDelete(),
                };
            }
        }, 'ids');
    }
}
