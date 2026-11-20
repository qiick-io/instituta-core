<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Initial super admin (CreateSuperAdminSeeder)
    |--------------------------------------------------------------------------
    |
    | In `local` and `testing` these values give a checkout its seeded login. Elsewhere the admin
    | is created only when the password is explicitly set, is not `password` (case-insensitively)
    | and passes `Password::defaults()`; a skipped seed says why. An existing user is never
    | modified outside local/testing. `instituta:install` and `instituta:create-user` use the same
    | password check.
    |
    */

    'first_name' => env('INITIAL_SUPER_ADMIN_FIRST_NAME', 'Super'),
    'last_name' => env('INITIAL_SUPER_ADMIN_LAST_NAME', 'Admin'),
    'email' => env('INITIAL_SUPER_ADMIN_EMAIL', 'superadmin@example.com'),
    'password' => env('INITIAL_SUPER_ADMIN_PASSWORD'),
];
