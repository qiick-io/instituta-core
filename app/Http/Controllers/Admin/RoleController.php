<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\SaveRoleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListQueryRequest;
use App\Http\Requests\Admin\RoleRequest;
use App\Http\Resources\Admin\RoleResource;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings pages for roles (`/settings/roles`): the list, saving and deleting.
 * Form pages live in {@see RoleFormController}.
 *
 * Copied from excubia-app.
 */
class RoleController extends Controller
{
    public function index(ListQueryRequest $request): Response
    {
        Gate::authorize('viewAny', Role::class);

        return Inertia::render('admin/roles/index', [
            'roles' => RoleResource::collection(Role::query()
                ->withCount('permissions')
                ->orderBy('name')
                ->paginate($request->perPage())
                ->withQueryString()),
        ]);
    }

    public function store(RoleRequest $request, SaveRoleAction $saveRole): RedirectResponse
    {
        $saveRole->execute(new Role, $request->string('name')->toString(), $request->permissions());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return to_route('roles.index');
    }

    public function update(RoleRequest $request, Role $role, SaveRoleAction $saveRole): RedirectResponse
    {
        $saveRole->execute($role, $request->string('name')->toString(), $request->permissions());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('roles.index');
    }

    public function destroy(Role $role): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $role->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role deleted.')]);

        return to_route('roles.index');
    }
}
