<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The default seed: permissions, roles and the development super admin (#362). The demo agency
 * is a separate seed (`--seeder=DemoAgencySeeder`) and seeds the super admin too.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            CreateSuperAdminSeeder::class,
        ]);
    }
}
