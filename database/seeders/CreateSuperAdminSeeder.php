<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\InitialSuperAdminPasswordService;
use Illuminate\Database\Seeder;

/**
 * Creates the initial super admin from `config/super_admin.php` under the shared fleet rule.
 *
 * In `local` and `testing` the seeded login keeps its existing behaviour: the password comes from
 * `.env`, and an existing user with that email keeps their password but gets the role, a verified
 * email and the active flag back. Elsewhere a strong, explicitly configured password is required,
 * and an existing user is never modified: a server must never get a known-credential admin or
 * have an operator's account rewritten by a re-seed.
 */
class CreateSuperAdminSeeder extends Seeder
{
    /** The environments where a seeded login is a convenience, not a risk. */
    public const array ENVIRONMENTS = InitialSuperAdminPasswordService::ENVIRONMENTS;

    public function run(InitialSuperAdminPasswordService $passwordValidation): void
    {
        $this->seedSuperAdmin($passwordValidation);
    }

    /**
     * The seeded super admin, or null when the environment, password or existing user forbids it.
     */
    public function seedSuperAdmin(InitialSuperAdminPasswordService $passwordValidation): ?User
    {
        $environment = app()->environment();
        $configuredPassword = config('super_admin.password');
        $password = is_string($configuredPassword) ? $configuredPassword : null;
        $reason = $passwordValidation->failureReason($password, $environment);

        if ($reason !== null) {
            $this->command?->warn("Skipped the initial super admin: {$reason}");

            return null;
        }

        $user = User::query()->firstOrNew(['email' => (string) config('super_admin.email')]);

        if ($user->exists && ! in_array($environment, self::ENVIRONMENTS, true)) {
            $this->command?->warn('Skipped the initial super admin: a user with the configured email already exists.');

            return null;
        }

        if (! $user->exists) {
            $user->first_name = (string) config('super_admin.first_name');
            $user->last_name = (string) config('super_admin.last_name');
            $user->password = (string) $password;
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'is_active' => true])->save();
        $user->assignRole(RoleEnum::SuperAdmin->value);

        return $user;
    }
}
