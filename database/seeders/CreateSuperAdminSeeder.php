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
 * In `local` and `testing` the password may be the known default. Elsewhere a strong,
 * explicitly configured password is required. When the configured email already exists the
 * seeder is a no-op in every environment: it never forceFills an existing user.
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

        $email = (string) config('super_admin.email');
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $this->command?->warn('Skipped the initial super admin: a user with the configured email already exists.');

            return null;
        }

        $user = (new User)->forceFill([
            'first_name' => (string) config('super_admin.first_name'),
            'last_name' => (string) config('super_admin.last_name'),
            'email' => $email,
            'password' => (string) $password,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->save();
        $user->assignRole(RoleEnum::SuperAdmin->value);

        return $user;
    }
}
