<?php

declare(strict_types=1);

/**
 * Admin routes for users, user groups, roles and permissions.
 *
 * All routes require `auth` and `verified`. Each action is gated by a Spatie `permission:*`
 * middleware alias; policies enforce record-level invariants.
 *
 * Structure from externa-core / excubia-app: users at `/users`, groups at `/groups`, roles and permissions under
 * `/settings/*`.
 */

use App\Enums\PermissionEnum;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\RoleFormController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserGroupController;
use App\Http\Controllers\Admin\UserGroupTrashController;
use App\Http\Controllers\Admin\UserPasswordResetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('users/assignable-roles', [UserController::class, 'assignableRoles'])
        ->middleware('permission:'.PermissionEnum::CanShowUsers->value)
        ->name('users.assignable-roles');

    Route::get('users', [UserController::class, 'index'])
        ->middleware('permission:'.PermissionEnum::CanShowUsers->value)
        ->name('users.index');

    Route::post('users', [UserController::class, 'store'])
        ->middleware('permission:'.PermissionEnum::CanCreateUsers->value)
        ->name('users.store');

    Route::put('users/{user}', [UserController::class, 'update'])
        ->middleware('permission:'.PermissionEnum::CanEditUsers->value)
        ->name('users.update');

    Route::delete('users/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:'.PermissionEnum::CanDeleteUsers->value)
        ->name('users.destroy');

    Route::post('users/{user}/password-reset', UserPasswordResetController::class)
        ->middleware([
            'permission:'.PermissionEnum::CanEditUsers->value,
            'throttle:6,1',
        ])
        ->name('users.password-reset');

    Route::get('groups', [UserGroupController::class, 'index'])
        ->middleware('permission:'.PermissionEnum::CanShowGroups->value)
        ->name('groups.index');

    Route::post('groups', [UserGroupController::class, 'store'])
        ->middleware('permission:'.PermissionEnum::CanCreateGroups->value)
        ->name('groups.store');

    Route::put('groups/{group}', [UserGroupController::class, 'update'])
        ->middleware('permission:'.PermissionEnum::CanEditGroups->value)
        ->name('groups.update');

    Route::delete('groups/{group}', [UserGroupController::class, 'destroy'])
        ->middleware('permission:'.PermissionEnum::CanDeleteGroups->value)
        ->name('groups.destroy');

    Route::post('groups/{group}/restore', [UserGroupTrashController::class, 'restore'])
        ->middleware('permission:'.PermissionEnum::CanRestoreGroups->value)
        ->name('groups.restore');

    Route::delete('groups/{group}/force', [UserGroupTrashController::class, 'forceDelete'])
        ->middleware('permission:'.PermissionEnum::CanForceDeleteGroups->value)
        ->name('groups.force-delete');

    // The permission depends on the action: BulkUserGroupActionRequest checks it.
    Route::post('groups/bulk-actions', [UserGroupTrashController::class, 'bulkActions'])
        ->name('groups.bulk-actions');

    Route::prefix('settings')->group(function (): void {
        Route::get('roles', [RoleController::class, 'index'])
            ->middleware('permission:'.PermissionEnum::CanShowRoles->value)
            ->name('roles.index');

        Route::get('roles/create', [RoleFormController::class, 'create'])
            ->middleware('permission:'.PermissionEnum::CanCreateRoles->value)
            ->name('roles.create');

        Route::post('roles', [RoleController::class, 'store'])
            ->middleware('permission:'.PermissionEnum::CanCreateRoles->value)
            ->name('roles.store');

        // View (incl. built-in read-only); RoleFormController / RolePolicy gate writes.
        Route::get('roles/{role}/edit', [RoleFormController::class, 'edit'])
            ->middleware('permission:'.PermissionEnum::CanShowRoles->value)
            ->name('roles.edit');

        Route::put('roles/{role}', [RoleController::class, 'update'])
            ->middleware('permission:'.PermissionEnum::CanEditRoles->value)
            ->name('roles.update');

        Route::delete('roles/{role}', [RoleController::class, 'destroy'])
            ->middleware('permission:'.PermissionEnum::CanDeleteRoles->value)
            ->name('roles.destroy');

        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware('permission:'.PermissionEnum::CanShowPermissions->value)
            ->name('permissions.index');

        Route::post('permissions/sync', [PermissionController::class, 'sync'])
            ->middleware('permission:'.PermissionEnum::CanEditPermissions->value)
            ->name('permissions.sync');
    });

    Route::redirect('roles', '/settings/roles');
    Route::redirect('roles/create', '/settings/roles/create');
    Route::redirect('roles/{role}/edit', '/settings/roles/{role}/edit');
    Route::redirect('permissions', '/settings/permissions');
});
