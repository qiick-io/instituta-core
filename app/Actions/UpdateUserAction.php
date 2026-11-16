<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\Authorization\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;

/**
 * Updates a dashboard user. A null password keeps the current one.
 *
 * Copied from excubia-app.
 */
class UpdateUserAction
{
    public function __construct(
        private SuperAdminGuardService $superAdminGuard,
        private SyncUserRolesAction $syncRoles,
        private SyncUserGroupsAction $syncGroups,
    ) {}

    /**
     * @param  list<string>  $roles
     * @param  list<int>|null  $groupIds  null keeps the current user groups
     *
     * @throws ValidationException when the change would leave no active super-admin
     */
    public function execute(User $user, string $firstName, ?string $lastName, string $email, ?string $password, array $roles, bool $isActive, ?array $groupIds = null): User
    {
        return $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($user, $firstName, $lastName, $email, $password, $roles, $isActive, $groupIds): User {
            $user->forceFill([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'is_active' => $isActive,
            ]);

            if ($password !== null) {
                $user->password = $password;
            }

            $user->save();
            $this->syncRoles->execute($user, $roles);

            if ($groupIds !== null) {
                $this->syncGroups->execute($user, $groupIds);
            }

            return $user;
        });
    }
}
