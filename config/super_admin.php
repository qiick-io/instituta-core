<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Development super admin (CreateSuperAdminSeeder)
    |--------------------------------------------------------------------------
    |
    | The login `migrate --seed` gives a local checkout, as in the other qiick apps. The seeder
    | runs only in `local` and `testing`, and only when a password is set: servers create their
    | first admin with `instituta:install` or `instituta:create-user`.
    |
    */

    'first_name' => env('INITIAL_SUPER_ADMIN_FIRST_NAME', 'Super'),
    'last_name' => env('INITIAL_SUPER_ADMIN_LAST_NAME', 'Admin'),
    'email' => env('INITIAL_SUPER_ADMIN_EMAIL', 'superadmin@example.com'),
    'password' => env('INITIAL_SUPER_ADMIN_PASSWORD'),
];
