<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\Authorization\InstanceSuperAdminGroupService;
use App\Services\Authorization\PermissionsTeamContextService;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the user's organisation-scoped Spatie roles and syncs instance super-admin
 * group membership. Role changes are not model attributes, so activity is logged here.
 *
 * Super-admin is never written to `model_has_roles` (teams require organisation_id).
 */
class SyncUserRolesAction
{
    public function __construct(
        private InstanceSuperAdminGroupService $instanceSuperAdmins,
        private PermissionsTeamContextService $teamContext,
    ) {}

    /**
     * @param  list<string>  $roles  role names (may include super-admin)
     * @param  string|null  $organisationId  required when any non-super-admin role is present
     */
    public function execute(User $user, array $roles, ?string $organisationId = null): void
    {
        $wantsSuperAdmin = in_array(RoleEnum::SuperAdmin->value, $roles, true);
        $organisationRoles = array_values(array_filter(
            $roles,
            static fn (string $role): bool => $role !== RoleEnum::SuperAdmin->value,
        ));

        if ($organisationRoles !== [] && ($organisationId === null || $organisationId === '')) {
            throw ValidationException::withMessages([
                'roles' => __('Organisation-scoped roles require an organisation context when teams are enabled.'),
            ]);
        }

        $this->instanceSuperAdmins->sync($user, $wantsSuperAdmin);

        if ($organisationId !== null && $organisationId !== '') {
            $this->teamContext->setOrganisation($organisationId);
            $user->syncRoles($organisationRoles);
        }

        $syncedOrganisationRoles = $organisationId !== null && $organisationId !== ''
            ? $user->roles()->orderBy('name')->get(['id', 'name'])
            : collect();

        activity()
            ->performedOn($user)
            ->event('roles_synced')
            ->withProperties([
                'organisation_id' => $organisationId,
                'is_super_admin' => $wantsSuperAdmin,
                'role_ids' => $syncedOrganisationRoles->pluck('id')->values()->all(),
                'role_names' => $syncedOrganisationRoles->pluck('name')->values()->all(),
            ])
            ->log('User roles synced');
    }
}
