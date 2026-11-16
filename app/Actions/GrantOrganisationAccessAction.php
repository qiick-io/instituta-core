<?php

declare(strict_types=1);

namespace App\Actions;

use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum as BrainRoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use App\Services\SessionPerimeterService;

/**
 * Gives a dashboard user a perimeter in an organisation: their person principal plus an
 * organisation-wide grant.
 *
 * Every brain screen resolves its perimeter from the signed-in user's person principal
 * ({@see SessionPerimeterService}). A user without one signs in to an empty
 * app, so every way of creating a user (install, `instituta:create-user`, the Users admin
 * page) goes through this action. Calling it again for the same user and organisation
 * updates the grant instead of adding a second principal.
 */
class GrantOrganisationAccessAction
{
    /**
     * @param  VisibilityEnum|null  $maxVisibility  Widest visibility the grant reads; defaults
     *                                              from the role (owner restricted, member internal, guest public).
     */
    public function execute(User $user, Organisation $organisation, BrainRoleEnum $role, ?VisibilityEnum $maxVisibility = null): Principal
    {
        /** @var Principal $principal */
        $principal = Principal::query()->firstOrCreate(
            [
                'organisation_id' => $organisation->id,
                'user_id' => $user->id,
                'type' => PrincipalTypeEnum::Person,
            ],
            ['name' => $user->name],
        );

        Grant::query()->updateOrCreate(
            [
                'principal_id' => $principal->id,
                'scope_type' => ScopeTypeEnum::Organisation,
                'scope_id' => $organisation->id,
            ],
            [
                'role' => $role,
                'max_visibility' => $maxVisibility ?? self::defaultVisibilityFor($role),
            ],
        );

        return $principal;
    }

    /**
     * Owners see everything, members the internal content, guests only what is public.
     */
    public static function defaultVisibilityFor(BrainRoleEnum $role): VisibilityEnum
    {
        return match ($role) {
            BrainRoleEnum::Owner => VisibilityEnum::Restricted,
            BrainRoleEnum::Member => VisibilityEnum::Internal,
            BrainRoleEnum::Guest => VisibilityEnum::Public,
        };
    }
}
