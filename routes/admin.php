<?php

declare(strict_types=1);

/**
 * Admin routes for users, roles and permissions.
 *
 * All routes require `auth` and `verified`. Each action is gated by a Spatie `permission:*`
 * middleware alias; policies enforce record-level invariants.
 *
 * Structure from externa-core / excubia-app: users at `/users`, roles and permissions under
 * `/settings/*`.
 */

use App\Enums\PermissionEnum;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\RoleFormController;
use App\Http\Controllers\Admin\UserController;
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
