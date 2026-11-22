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
     * @param  string|null  $organisationId  Spatie team for non-super-admin roles
     *
     * @throws ValidationException when the change would leave no active super-admin
     */
    public function execute(
        User $user,
        string $firstName,
        ?string $lastName,
        ?string $username,
        string $email,
        ?string $password,
        array $roles,
        bool $isActive,
        ?array $groupIds = null,
        ?string $organisationId = null,
    ): User {
        return $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($user, $firstName, $lastName, $username, $email, $password, $roles, $isActive, $groupIds, $organisationId): User {
            $user->forceFill([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'username' => $username,
                'email' => $email,
                'is_active' => $isActive,
            ]);

            if ($password !== null) {
                $user->password = $password;
            }

            $user->save();

            $resolvedOrganisationId = $organisationId
                ?? (is_string(getPermissionsTeamId()) ? (string) getPermissionsTeamId() : null)
                ?? $user->principals()->where('type', 'person')->orderBy('id')->value('organisation_id');

            $this->syncRoles->execute(
                $user,
                $roles,
                is_string($resolvedOrganisationId) && $resolvedOrganisationId !== '' ? $resolvedOrganisationId : null,
            );

            if ($groupIds !== null) {
                $this->syncGroups->execute($user, $groupIds);
            }

            return $user;
        });
    }
}
