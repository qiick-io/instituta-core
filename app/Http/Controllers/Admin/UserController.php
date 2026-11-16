<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateUserAction;
use App\Actions\DeleteUserAction;
use App\Actions\UpdateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListQueryRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Services\SessionPerimeterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin pages for dashboard users, behind `can-*-users` (UserPolicy). Create and edit happen
 * in a drawer on the list page.
 *
 * Copied from excubia-app (adapted to Admin namespace); user groups as in externa-core.
 */
class UserController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE_COLUMNS = ['name', 'email', 'is_active', 'created_at', 'updated_at'];

    /**
     * Lists users with search, sort and pagination.
     */
    public function index(ListQueryRequest $request): Response|JsonResponse
    {
        $actor = $request->user();

        Gate::authorize('viewAny', User::class);

        $sort = $request->sortColumn(self::SORTABLE_COLUMNS, 'created_at');
        $direction = $request->sortDirection('desc');

        $query = $request->applySearch(User::query(), ['first_name', 'last_name', 'email']);
        $query->with(['roles:id,name', 'groups:id,name']);

        if ($sort === 'name') {
            $query->orderBy('first_name', $direction)->orderBy('last_name', $direction);
        } else {
            $query->orderBy($sort, $direction);
        }

        $users = $query
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        if ($request->expectsJson()) {
            return UserResource::collection($users)->response($request);
        }

        return Inertia::render('admin/users/index', [
            'users' => UserResource::collection($users),
            'filters' => $request->filters($sort, $direction),
            'roles' => StoreUserRequest::assignableRoleOptionsFor($actor instanceof User ? $actor : null),
        ]);
    }

    /**
     * The roles the user may grant, as the paginator JSON the registry picker reads.
     */
    public function assignableRoles(ListQueryRequest $request): JsonResponse
    {
        abort_unless(Gate::allows('create', User::class) || Gate::allows('viewAny', User::class), 403);

        $actor = $request->user();
        $search = mb_strtolower(trim($request->string('search')->toString()));
        $roles = array_values(array_filter(
            StoreUserRequest::assignableRoleOptionsFor($actor instanceof User ? $actor : null),
            fn (array $role): bool => $search === '' || str_contains(mb_strtolower($role['name']), $search),
        ));

        return response()->json(['data' => $roles, 'current_page' => 1, 'last_page' => 1]);
    }

    /**
     * Creates the user and, when the admin has a brain perimeter, makes them a member of the
     * admin's organisation so they do not sign in to an empty brain.
     */
    public function store(StoreUserRequest $request, CreateUserAction $createUser, SessionPerimeterService $sessionPerimeters): RedirectResponse
    {
        $actor = $request->user();
        $actorPrincipal = $actor instanceof User ? $sessionPerimeters->personPrincipal($actor) : null;

        $createUser->execute(
            $request->string('first_name')->toString(),
            $request->filled('last_name') ? $request->string('last_name')->toString() : null,
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->roles(),
            $request->boolean('is_active', true),
            organisation: $actorPrincipal?->organisation,
            groupIds: $request->groupIds(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('users.index');
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $updateUser): RedirectResponse
    {
        $updateUser->execute(
            $user,
            $request->string('first_name')->toString(),
            $request->filled('last_name') ? $request->string('last_name')->toString() : null,
            $request->string('email')->toString(),
            $request->filled('password') ? $request->string('password')->toString() : null,
            $request->roles(),
            $request->boolean('is_active'),
            $request->groupIds(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('users.index');
    }

    public function destroy(User $user, DeleteUserAction $deleteUser): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $deleteUser->execute($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('users.index');
    }
}
