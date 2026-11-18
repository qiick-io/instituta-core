<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Qiick\Oidc\Contracts\BreakGlassPolicyContract;

/**
 * Who still signs in locally (password, passkey) in SSO-only mode, so an outage of the identity
 * provider never locks the instance: an active super-admin with confirmed two-factor
 * authentication. Super-admin is the one role the IdP can never grant (the package refuses it),
 * so the break-glass account cannot be obtained through SSO; the second factor means a leaked
 * password alone does not get past SSO-only mode.
 *
 * Without SSO-only mode the policy is not consulted: SSO is one more button and every local
 * login keeps working while the IdP is down.
 */
final readonly class BreakGlassPolicyService implements BreakGlassPolicyContract
{
    public function isBreakGlassAccount(Authenticatable $user): bool
    {
        return $user instanceof User
            && $user->is_active
            && $user->hasRole(RoleEnum::SuperAdmin->value)
            && $user->hasEnabledTwoFactorAuthentication();
    }

    /**
     * Whether at least one account qualifies: SSO-only mode may not be turned on before.
     */
    public function hasBreakGlassAccount(): bool
    {
        return User::query()
            ->role(RoleEnum::SuperAdmin->value)
            ->where('is_active', true)
            ->whereNotNull('two_factor_secret')
            ->whereNotNull('two_factor_confirmed_at')
            ->exists();
    }
}
