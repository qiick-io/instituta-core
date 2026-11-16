<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\ChangeUserGroupsStateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkUserGroupActionRequest;
use App\Models\UserGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Admin trash for user groups: restore, permanent delete and bulk actions (UserGroupPolicy,
 * BulkUserGroupActionRequest).
 *
 * Copied from externa-core.
 */
class UserGroupTrashController extends Controller
{
    public function restore(UserGroup $group, ChangeUserGroupsStateAction $changeGroupsState): RedirectResponse
    {
        Gate::authorize('restore', $group);

        $changeGroupsState->execute('restore', [$group->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User group restored.')]);

        return to_route('groups.index', ['trashed' => 1]);
    }

    public function forceDelete(UserGroup $group, ChangeUserGroupsStateAction $changeGroupsState): RedirectResponse
    {
        Gate::authorize('forceDelete', $group);

        $changeGroupsState->execute('force_delete', [$group->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User group permanently deleted.')]);

        return to_route('groups.index', ['trashed' => 1]);
    }

    public function bulkActions(BulkUserGroupActionRequest $request, ChangeUserGroupsStateAction $changeGroupsState): RedirectResponse
    {
        /** @var 'delete'|'restore'|'force_delete' $action */
        $action = $request->string('action')->toString();

        $changeGroupsState->execute($action, $request->groupIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bulk action completed.')]);

        return back();
    }
}
