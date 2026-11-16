<?php

declare(strict_types=1);

namespace App\Actions;

use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Models\Grant;
use App\Models\Principal;
use App\Models\User;
use App\Models\UserGroupBrainAccess;

/**
 * Makes a user's group-given brain grants match their groups (#258). For each scope one of the
 * user's (non-deleted) groups gives, the user holds one grant marked with that group, with the
 * widest role any of their groups gives there. Group grants for scopes no group gives any more
 * are removed. A grant the person holds on their own (no group) is never changed: it wins.
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

        /** @var array<string, UserGroupBrainAccess> $widestByScope */
        $widestByScope = [];

        $accesses = UserGroupBrainAccess::query()
            ->whereIn('user_group_id', $user->groups()->pluck('user_groups.id'))
            ->orderBy('user_group_id')
            ->get();

        foreach ($accesses as $access) {
            $scopeKey = $access->scope_type->value.':'.$access->scope_id;
            $current = $widestByScope[$scopeKey] ?? null;

            if ($current === null || self::rank($access->role) > self::rank($current->role)) {
                $widestByScope[$scopeKey] = $access;
            }
        }

        $keptGrantIds = [];

        foreach ($widestByScope as $access) {
            /** @var Principal $principal */
            $principal = Principal::query()->firstOrCreate(
                ['organisation_id' => $access->organisation_id, 'user_id' => $user->id, 'type' => PrincipalTypeEnum::Person],
                ['name' => $user->name],
            );

            $existing = Grant::query()
                ->where('principal_id', $principal->id)
                ->where('scope_type', $access->scope_type)
                ->where('scope_id', $access->scope_id)
                ->first();

            // The person's own access wins over any group's.
            if ($existing !== null && $existing->user_group_id === null) {
                continue;
            }

            $grant = $existing ?? new Grant([
                'principal_id' => $principal->id,
                'scope_type' => $access->scope_type,
                'scope_id' => $access->scope_id,
            ]);
            $grant->forceFill([
                'user_group_id' => $access->user_group_id,
                'role' => $access->role,
                'max_visibility' => GrantOrganisationAccessAction::defaultVisibilityFor($access->role),
            ])->save();

            $keptGrantIds[] = $grant->id;
        }

        Grant::query()
            ->whereNotNull('user_group_id')
            ->whereIn('principal_id', Principal::query()->where('user_id', $user->id)->select('id'))
            ->whereNotIn('id', $keptGrantIds)
            ->delete();
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
