<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seed default application roles and attach permissions.
 *
 * Super-admin and admin receive every permission; reader receives only `can-show-*`.
 */
class RoleSeeder extends Seeder
{
    /**
     * Create roles and sync their permission sets.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');
        // Global role definitions: organisation_id null (fleet schema). Assignments are per org.
        $teamKey = config('permission.column_names.team_foreign_key', 'organisation_id');
        $definition = static fn (string $name): array => config('permission.teams')
            ? ['name' => $name, 'guard_name' => $guard, $teamKey => null]
            : ['name' => $name, 'guard_name' => $guard];

        $superAdmin = Role::query()->firstOrCreate(
            $definition(RoleEnum::SuperAdmin->value),
            ['is_system' => true, 'is_assignable' => true],
        );

        $admin = Role::query()->firstOrCreate(
            $definition(RoleEnum::Admin->value),
            ['is_system' => false, 'is_assignable' => true],
        );

        $reader = Role::query()->firstOrCreate(
            $definition(RoleEnum::Reader->value),
            ['is_system' => false, 'is_assignable' => true],
        );

        $allPermissions = Permission::query()->where('guard_name', $guard)->pluck('name');

        $superAdmin->syncPermissions($allPermissions);
        $admin->syncPermissions($allPermissions);

        $readerPermissions = collect(PermissionEnum::cases())
            ->filter(fn (PermissionEnum $permission): bool => str_starts_with($permission->value, 'can-show-'))
            ->map(fn (PermissionEnum $permission): string => $permission->value)
            ->all();

        $reader->syncPermissions($readerPermissions);
    }
}
