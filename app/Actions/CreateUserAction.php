<?php

declare(strict_types=1);

namespace App\Actions;

use App\Brain\Enums\RoleEnum as BrainRoleEnum;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a dashboard user with the given roles. The email counts as verified because an
 * administrator (UI or CLI) created the account.
 *
 * With an organisation, the user also gets a perimeter in it (person principal + grant) in
 * the same transaction, so they never sign in to an empty brain.
 *
 * Copied from excubia-app; the organisation step is Instituta's.
 */
class CreateUserAction
{
    public function __construct(
        private SyncUserRolesAction $syncRoles,
        private GrantOrganisationAccessAction $grantOrganisationAccess,
        private SyncUserGroupsAction $syncGroups,
    ) {}

    /**
     * @param  list<string>  $roles  Application (Spatie) roles.
     * @param  list<int>|null  $groupIds  User groups to join; null joins none.
     */
    public function execute(
        string $firstName,
        ?string $lastName,
        string $email,
        string $password,
        array $roles,
        bool $isActive = true,
        ?Organisation $organisation = null,
        BrainRoleEnum $brainRole = BrainRoleEnum::Member,
        ?array $groupIds = null,
    ): User {
        return DB::transaction(function () use ($firstName, $lastName, $email, $password, $roles, $isActive, $organisation, $brainRole, $groupIds): User {
            $user = (new User)->forceFill([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password' => $password,
                'is_active' => $isActive,
                'email_verified_at' => now(),
            ]);
            $user->save();
            $this->syncRoles->execute($user, $roles);

            if ($groupIds !== null) {
                $this->syncGroups->execute($user, $groupIds);
            }

            if ($organisation instanceof Organisation) {
                $this->grantOrganisationAccess->execute($user, $organisation, $brainRole);
            }

            return $user;
        });
    }
}
