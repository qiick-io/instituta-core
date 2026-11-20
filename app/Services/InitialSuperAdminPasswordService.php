<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * The one fleet rule for the first super admin's password (knowledge decision 2026-11-19).
 *
 * In local and testing the password comes from .env and keeps the development behaviour. Everywhere
 * else it must be explicitly set, must not be the well-known `password`, and must satisfy
 * `Password::defaults()`. The seeder and both CLI commands call this class so they cannot drift.
 * Existing accounts are a separate concern: the callers decide whether to leave them untouched.
 */
class InitialSuperAdminPasswordService
{
    /** The environments where a seeded login is a convenience, not a risk. */
    public const array ENVIRONMENTS = ['local', 'testing'];

    /**
     * Why this password cannot create a super admin in this environment, or null when it can.
     */
    public function failureReason(?string $password, string $environment): ?string
    {
        if ($password === null || $password === '') {
            return 'INITIAL_SUPER_ADMIN_PASSWORD is not set.';
        }

        if (in_array($environment, self::ENVIRONMENTS, true)) {
            return null;
        }

        if (strcasecmp($password, 'password') === 0) {
            return 'INITIAL_SUPER_ADMIN_PASSWORD is the default password.';
        }

        $validator = Validator::make(['password' => $password], [
            'password' => ['required', 'string', Password::defaults()],
        ]);

        if ($validator->fails()) {
            return 'INITIAL_SUPER_ADMIN_PASSWORD does not meet Password::defaults(): '.$validator->errors()->first('password');
        }

        return null;
    }

    /**
     * Validation rules for a CLI password prompt; the closure is the check the seeder uses.
     *
     * @return array<int, mixed>
     */
    public function rules(string $environment): array
    {
        $rules = ['required', 'string'];

        if (in_array($environment, self::ENVIRONMENTS, true)) {
            // Keep the existing local/testing CLI validation while the fleet rule only binds
            // outside those environments.
            $rules[] = Password::default();
        }

        $rules[] = function (string $attribute, mixed $value, Closure $fail) use ($environment): void {
            $reason = $this->failureReason(is_string($value) ? $value : null, $environment);

            if ($reason !== null) {
                $fail($reason);
            }
        };

        return $rules;
    }
}
