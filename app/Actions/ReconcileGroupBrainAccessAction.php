<?php

declare(strict_types=1);

namespace App\Actions;

use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Grant;
use App\Models\Principal;
use App\Models\User;
use App\Models\UserGroupBrainAccess;
use App\Services\PerimeterResolverService;
use Illuminate\Support\Collection;

/**
 * Makes a user's group-given brain grants match their groups (#258). For each scope one of the
 * user's (non-deleted) groups gives, the user holds one grant marked with a group: the widest role
 * and visibility any of their groups gives there, and the areas and path prefixes they give added
 * up (#334); a group without a limit lifts it. Group grants for scopes no group gives any more are
 * removed. A grant the person holds on their own (no group) is never changed; it sits next to the
 * group grant and the perimeter adds the two up ({@see PerimeterResolverService::fromGrants()}).
 *
 * Called with every user a change can affect: members added or removed, a group's access edited,
 * a group deleted, restored or force-deleted, a user's groups changed in the user form.
 */
final class ReconcileGroupBrainAccessAction
{
    /**
     * @param  iterable<User>  $users
     */
    public function execute(iterable $users): void
    {
        foreach ($users as $user) {
            $this->reconcile($user);
        }
    }

    private function reconcile(User $user): void
    {
        $user->unsetRelation('groups');

        $accessesByScope = UserGroupBrainAccess::query()
            ->whereIn('user_group_id', $user->groups()->pluck('user_groups.id'))
            ->orderBy('user_group_id')
            ->get()
            ->groupBy(static fn (UserGroupBrainAccess $access): string => $access->scope_type->value.':'.$access->scope_id);

        $keptGrantIds = [];

        foreach ($accessesByScope as $accesses) {
            /** @var UserGroupBrainAccess $widest */
            $widest = $accesses->sortByDesc(static fn (UserGroupBrainAccess $access): int => self::rank($access->role))->first();

            /** @var Principal $principal */
            $principal = Principal::query()->firstOrCreate(
                ['organisation_id' => $widest->organisation_id, 'user_id' => $user->id, 'type' => PrincipalTypeEnum::Person],
                ['name' => $user->name],
            );

            $grant = Grant::query()
                ->where('principal_id', $principal->id)
                ->whereNotNull('user_group_id')
                ->where('scope_type', $widest->scope_type)
                ->where('scope_id', $widest->scope_id)
                ->first() ?? new Grant([
                    'principal_id' => $principal->id,
                    'scope_type' => $widest->scope_type,
                    'scope_id' => $widest->scope_id,
                ]);

            $grant->forceFill([
                'user_group_id' => $widest->user_group_id,
                'role' => $widest->role,
                'max_visibility' => self::widestVisibility($accesses),
                'areas' => self::addedUp($accesses->map(static fn (UserGroupBrainAccess $access): array => $access->areas)),
                'path_prefixes' => self::addedUp($accesses->map(static fn (UserGroupBrainAccess $access): array => $access->path_prefixes)),
            ])->save();

            $keptGrantIds[] = $grant->id;
        }

        Grant::query()
            ->whereNotNull('user_group_id')
            ->whereIn('principal_id', Principal::query()->where('user_id', $user->id)->select('id'))
            ->whereNotIn('id', $keptGrantIds)
            ->delete();
    }

    /**
     * @param  Collection<int, UserGroupBrainAccess>  $accesses
     */
    private static function widestVisibility(Collection $accesses): VisibilityEnum
    {
        $visibilities = $accesses->map(static fn (UserGroupBrainAccess $access): VisibilityEnum => $access->max_visibility
            ?? GrantOrganisationAccessAction::defaultVisibilityFor($access->role));

        foreach ([VisibilityEnum::Restricted, VisibilityEnum::Internal] as $visibility) {
            if ($visibilities->contains($visibility)) {
                return $visibility;
            }
        }

        return VisibilityEnum::Public;
    }

    /**
     * The union of several limit lists, or no limit when one of them has none.
     *
     * @param  Collection<int, list<string>>  $limitLists
     * @return list<string>
     */
    private static function addedUp(Collection $limitLists): array
    {
        if ($limitLists->contains(static fn (array $limits): bool => $limits === [])) {
            return [];
        }

        return array_values(array_unique($limitLists->flatten()->all()));
    }

    private static function rank(RoleEnum $role): int
    {
        return match ($role) {
            RoleEnum::Owner => 3,
            RoleEnum::Member => 2,
            RoleEnum::Guest => 1,
        };
    }
}
