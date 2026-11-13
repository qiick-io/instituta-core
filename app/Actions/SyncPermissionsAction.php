<?php

declare(strict_types=1);

namespace App\Actions;

use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Re-syncs PermissionEnum into the database (and the TypeScript enum) then resets built-in
 * roles via {@see RoleSeeder}. Custom roles are left untouched. Used by the permissions page
 * Sync button.
 *
 * Adapted from excubia-app: Instituta owns sync in `instituta:sync-permissions` + RoleSeeder.
 */
class SyncPermissionsAction
{
    public function execute(): void
    {
        Artisan::call('instituta:sync-permissions');
        (new RoleSeeder)->run();
    }
}
