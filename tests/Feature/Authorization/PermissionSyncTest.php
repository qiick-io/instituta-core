<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('instituta:sync-permissions creates database rows for every enum case', function (): void {
    Artisan::call('instituta:sync-permissions');

    expect(Permission::query()->count())->toBe(count(PermissionEnum::cases()));

    foreach (PermissionEnum::values() as $permissionName) {
        expect(Permission::query()->where('name', $permissionName)->exists())->toBeTrue();
    }
});

test('instituta:sync-permissions is idempotent', function (): void {
    Artisan::call('instituta:sync-permissions');
    $firstCount = Permission::query()->count();

    Artisan::call('instituta:sync-permissions');

    expect(Permission::query()->count())->toBe($firstCount)
        ->and($firstCount)->toBe(count(PermissionEnum::cases()));
});

test('instituta:sync-permissions emits the TypeScript permission enum', function (): void {
    $path = resource_path('js/enums/permission-enum.ts');

    if (File::exists($path)) {
        File::delete($path);
    }

    Artisan::call('instituta:sync-permissions');

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    foreach (PermissionEnum::cases() as $case) {
        expect($contents)->toContain($case->name)
            ->and($contents)->toContain($case->value);
    }
});

test('instituta:sync-permissions with prune removes stale permissions', function (): void {
    Artisan::call('instituta:sync-permissions');

    Permission::query()->create([
        'name' => 'can-obsolete-module',
        'guard_name' => config('auth.defaults.guard', 'web'),
    ]);

    Artisan::call('instituta:sync-permissions', ['--prune' => true]);

    expect(Permission::query()->where('name', 'can-obsolete-module')->exists())->toBeFalse();
});

test('instituta:upgrade grants new permissions to admin without restoring revoked ones', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    Permission::query()
        ->where('name', PermissionEnum::CanManageSettings->value)
        ->delete();
    Role::findByName(RoleEnum::Admin->value)->revokePermissionTo(PermissionEnum::CanDeleteUsers->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('instituta:upgrade', ['--force' => true, '--no-migrate' => true])->assertSuccessful();

    $admin = Role::findByName(RoleEnum::Admin->value);

    expect($admin->hasPermissionTo(PermissionEnum::CanManageSettings->value))->toBeTrue()
        ->and($admin->hasPermissionTo(PermissionEnum::CanDeleteUsers->value))->toBeFalse()
        ->and(Role::findByName(RoleEnum::Reader->value)->hasPermissionTo(PermissionEnum::CanManageSettings->value))->toBeFalse();
});
