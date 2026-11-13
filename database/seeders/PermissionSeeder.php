<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Seed Spatie permissions from application enums via instituta:sync-permissions.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Sync permission records to match {@see PermissionEnum} definitions.
     */
    public function run(): void
    {
        Artisan::call('instituta:sync-permissions');
    }
}
