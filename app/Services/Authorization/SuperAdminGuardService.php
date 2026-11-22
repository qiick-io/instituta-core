<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps at least one active super-admin, so the instance never locks itself out. A user counts
 * as super-admin only through the instance super-admin group, so group changes (members,
 * roles, deletion) run through {@see preventLosingLastSuperAdmin()} too.
 *
 * Copied from excubia-app; group inheritance as in externa-core.
 */
class SuperAdminGuardService
{
    public function __construct(private EffectivePermissionResolverService $permissionResolver) {}

    /**
     * Whether the user is the only active super-admin left.
     */
    public function isLastSuperAdmin(User $user): bool
    {
        if (! $user->is_active || ! $this->permissionResolver->isSuperAdmin($user)) {
            return false;
        }

        return $this->activeSuperAdmins()->whereKeyNot($user->getKey())->doesntExist();
    }

    /**
     * Runs a change (role, activation or deletion) and rolls it back when no active
     * super-admin would be left.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     *
     * @throws ValidationException under `$errorKey` when the change is refused
     */
    public function preventLosingLastSuperAdmin(Closure $change, string $errorKey = 'roles'): mixed
    {
        return DB::transaction(function () use ($change, $errorKey): mixed {
            $hadSuperAdmin = $this->activeSuperAdmins()->exists();
            $result = $change();

            if ($hadSuperAdmin && $this->activeSuperAdmins()->doesntExist()) {
                throw ValidationException::withMessages([
                    $errorKey => __('The last active super-admin must stay an active super-admin.'),
                ]);
            }

            return $result;
        });
    }

    /**
     * @return Builder<User>
     */
    private function activeSuperAdmins(): Builder
    {
        // Only membership in an is_instance group counts (not any group with the role).
        return User::query()
            ->where('is_active', true)
            ->whereHas(
                'groups',
                static fn (Builder $groups) => $groups->where('is_instance', true),
            );
    }
}
