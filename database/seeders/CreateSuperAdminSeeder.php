<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the development super admin from `config/super_admin.php` (#362), so a local checkout
 * signs in with `superadmin@example.com`, as in the other qiick apps.
 *
 * Only in `local` and `testing`, and only with `INITIAL_SUPER_ADMIN_PASSWORD` set: a server that
 * runs `migrate --seed --force` must never get an account with known credentials. Servers create
 * the first admin with `instituta:install` or `instituta:create-user`.
 *
 * Idempotent on the email: an existing user keeps their password, and gets the role, a verified
 * email and the active flag back.
 */
class CreateSuperAdminSeeder extends Seeder
{
    /** The environments where a seeded login is a convenience, not a risk. */
    public const array ENVIRONMENTS = ['local', 'testing'];

    public function run(): void
    {
        $this->seedSuperAdmin();
    }

    /**
     * The super admin, or null when this environment or configuration does not seed one.
     */
    public function seedSuperAdmin(): ?User
    {
        if (! app()->environment(self::ENVIRONMENTS)) {
            $this->command?->warn('Skipped the development super admin outside local and testing: create the first admin with `php artisan instituta:create-user`.');

            return null;
        }

        $password = config('super_admin.password');

        if (! is_string($password) || $password === '') {
            $this->command?->warn('Skipped the development super admin: INITIAL_SUPER_ADMIN_PASSWORD is empty.');

            return null;
        }

        $user = User::query()->firstOrNew(['email' => (string) config('super_admin.email')]);

        if (! $user->exists) {
            $user->first_name = (string) config('super_admin.first_name');
            $user->last_name = (string) config('super_admin.last_name');
            $user->password = $password;
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'is_active' => true])->save();
        $user->assignRole(RoleEnum::SuperAdmin->value);

        return $user;
    }
}
