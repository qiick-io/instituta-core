<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\ChangeUserGroupsStateAction;
use App\Actions\SaveUserGroupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListQueryRequest;
use App\Http\Requests\Admin\StoreUserGroupRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserGroupRequest;
use App\Http\Resources\Admin\UserGroupResource;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\GroupBrainScopesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin list, create, edit and soft delete for user groups and their members and roles,
 * behind `can-*-groups` (UserGroupPolicy). Create and edit happen in a drawer on the list page.
 *
 * Restore, permanent delete and bulk actions live in {@see UserGroupTrashController}.
 *
 * Copied from externa-core.
 */
class UserGroupController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE_COLUMNS = ['name', 'created_at', 'updated_at'];

    /**
     * Lists groups with search, trash, sort and pagination. A JSON request returns the
     * paginated resource the group pickers read.
     */
    public function index(ListQueryRequest $request): Response|JsonResponse
    {
        Gate::authorize('viewAny', UserGroup::class);

        $actor = $request->user();
        $isTrashed = $request->boolean('trashed');
        $sort = $request->sortColumn(self::SORTABLE_COLUMNS, 'name');
        $direction = $request->sortDirection('asc');

        $query = $request->applySearch(UserGroup::query(), ['name', 'description'])
            ->withCount(['users', 'roles'])
            ->with(['roles:id,name', 'users:id,public_id,first_name,last_name,email', 'brainAccess']);

        // Organisation admins only see groups of the current team; instance SA sees all.
        if ($actor instanceof User && ! app(EffectivePermissionResolverService::class)->isSuperAdmin($actor)) {
            $teamId = getPermissionsTeamId();
            $query->where('is_instance', false)->where('organisation_id', is_string($teamId) ? $teamId : '');
        }

        if ($isTrashed) {
            $query->onlyTrashed();
        }

        $groups = $query
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        if ($request->expectsJson()) {
            return UserGroupResource::collection($groups)->response($request);
        }

        return Inertia::render('admin/groups/index', [
            'groups' => UserGroupResource::collection($groups),
            'filters' => [...$request->filters($sort, $direction), 'trashed' => $isTrashed],
            'roles' => StoreUserRequest::assignableRoleOptionsFor($actor instanceof User ? $actor : null),
            // The organisation and clients the actor may give a group brain access to (#258).
            'brainScopes' => $actor instanceof User ? app(GroupBrainScopesService::class)->optionsFor($actor) : [],
            // The areas that access can be limited to (#334).
            'brainAreas' => $actor instanceof User ? app(GroupBrainScopesService::class)->areasFor($actor) : [],
        ]);
    }

    public function store(StoreUserGroupRequest $request, SaveUserGroupAction $saveGroup): RedirectResponse
    {
        $saveGroup->execute(
            new UserGroup,
            $request->string('name')->toString(),
            $request->filled('description') ? $request->string('description')->toString() : null,
            $request->userIds() ?? [],
            $request->roleIds() ?? [],
            $request->brainAccess(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User group created.')]);

        return to_route('groups.index');
    }

    public function update(UpdateUserGroupRequest $request, UserGroup $group, SaveUserGroupAction $saveGroup): RedirectResponse
    {
        $saveGroup->execute(
            $group,
            $request->string('name')->toString(),
            $request->filled('description') ? $request->string('description')->toString() : null,
            $request->userIds(),
            $request->roleIds(),
            $request->brainAccess(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User group updated.')]);

        return to_route('groups.index');
    }

    public function destroy(UserGroup $group, ChangeUserGroupsStateAction $changeGroupsState): RedirectResponse
    {
        Gate::authorize('delete', $group);

        $changeGroupsState->execute('delete', [$group->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User group deleted.')]);

        return to_route('groups.index');
    }
}
