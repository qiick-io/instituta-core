<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\Authorization\SuperAdminGuardService;

/**
 * Deletes a dashboard user. Roles and passkeys go with the row (cascade).
 *
 * Copied from excubia-app.
 */
class DeleteUserAction
{
    public function __construct(private SuperAdminGuardService $superAdminGuard) {}

    public function execute(User $user): void
    {
        $this->superAdminGuard->preventLosingLastSuperAdmin(fn () => $user->delete(), 'user');
    }
}
