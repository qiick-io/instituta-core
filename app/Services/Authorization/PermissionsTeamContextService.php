<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Brain\Data\PerimeterData;
use App\Models\User;
use App\Services\PerimeterScopeColumnsService;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sets Spatie's permissions team id to the current organisation and clears cached
 * role/permission relations on switch (core hardening C29).
 *
 * Instance super-admin does not use this path; it is resolved through the instance group.
 */
final class PermissionsTeamContextService
{
    public function __construct(
        private readonly PermissionRegistrar $permissionRegistrar,
        private readonly PerimeterScopeColumnsService $scopeColumns,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Switch the Spatie team to the given organisation (or clear it when null).
     * Unsets loaded roles/permissions on the signed-in user (and on `$forgetOn` when given)
     * so the next check reloads for the new team.
     */
    public function setOrganisation(?string $organisationId, ?User $forgetOn = null): void
    {
        $previous = $this->permissionRegistrar->getPermissionsTeamId();

        if ($previous === $organisationId) {
            return;
        }

        $this->permissionRegistrar->setPermissionsTeamId($organisationId);
        $this->permissionRegistrar->forgetCachedPermissions();

        $authenticatedUser = $this->auth->guard()->user();

        if ($authenticatedUser instanceof User) {
            $authenticatedUser->unsetRelation('roles');
            $authenticatedUser->unsetRelation('permissions');
        }

        if ($forgetOn instanceof User) {
            $forgetOn->unsetRelation('roles');
            $forgetOn->unsetRelation('permissions');
        }
    }

    /**
     * Organisation id for a resolved perimeter (organisation / company / product scope).
     */
    public function organisationIdFromPerimeter(PerimeterData $perimeter): ?string
    {
        [$organisationId] = $this->scopeColumns->columnsFor($perimeter);

        return is_string($organisationId) && $organisationId !== '' ? $organisationId : null;
    }
}
