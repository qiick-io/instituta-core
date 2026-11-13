<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

function permissionsPageUser(RoleEnum|string $role): User
{
    return User::factory()->create()->assignRole($role instanceof RoleEnum ? $role->value : $role);
}

it('serves the permissions page under settings', function (): void {
    expect(route('permissions.index', absolute: false))->toBe('/settings/permissions')
        ->and(route('permissions.sync', absolute: false))->toBe('/settings/permissions/sync');
});

it('lets a reader list permissions but not sync', function (): void {
    // RoleSeeder grants can-show-permissions to reader.
    $user = permissionsPageUser(RoleEnum::Reader);

    $this->actingAs($user)->get(route('permissions.index'))->assertOk();
    $this->actingAs($user)->post(route('permissions.sync'))->assertForbidden();
});

it('sends guests to the login page', function (): void {
    $this->get(route('permissions.index'))->assertRedirect(route('login'));
});

it('lets a custom role with permission access open the permissions page', function (): void {
    Role::query()->create(['name' => 'access-manager', 'guard_name' => 'web'])->givePermissionTo([
        PermissionEnum::CanShowPermissions->value,
        PermissionEnum::CanEditPermissions->value,
    ]);

    $this->actingAs(permissionsPageUser('access-manager'))
        ->get(route('permissions.index'))
        ->assertOk();
});

it('lists every permission with the roles that hold it', function (): void {
    $this->actingAs(permissionsPageUser(RoleEnum::SuperAdmin))
        ->get(route('permissions.index', ['per_page' => 100]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/permissions/index')
            ->has('permissions.data', count(PermissionEnum::cases()))
            ->where('permissions.data', fn ($rows): bool => collect($rows)->pluck('name')->sort()->values()->all()
                === collect(PermissionEnum::values())->sort()->values()->all())
            ->where('permissions.data', fn ($rows): bool => collect($rows)->firstWhere('name', PermissionEnum::CanShowUsers->value)['roles']
                === [RoleEnum::Admin->value, RoleEnum::Reader->value, RoleEnum::SuperAdmin->value])
            ->where('permissions.data', fn ($rows): bool => collect($rows)->firstWhere('name', PermissionEnum::CanManageSettings->value)['roles']
                === [RoleEnum::Admin->value, RoleEnum::SuperAdmin->value]));
});

it('syncs the permissions from the enum idempotently', function (): void {
    Permission::findByName(PermissionEnum::CanShowActivityLogs->value)->delete();
    Role::findByName(RoleEnum::Reader->value)->givePermissionTo([
        PermissionEnum::CanShowUsers->value,
        PermissionEnum::CanCreateUsers->value,
    ]);
    $custom = Role::query()->create(['name' => 'auditor', 'guard_name' => 'web']);
    $custom->givePermissionTo(PermissionEnum::CanShowUsers->value);

    $manager = permissionsPageUser(RoleEnum::SuperAdmin);

    foreach ([1, 2] as $run) {
        $this->actingAs($manager)
            ->post(route('permissions.sync'))
            ->assertRedirect(route('permissions.index'))
            ->assertInertiaFlash('toast.type', 'success');

        expect(Permission::query()->pluck('name')->sort()->values()->all())
            ->toBe(collect(PermissionEnum::values())->sort()->values()->all());

        $readerPermissions = Role::findByName(RoleEnum::Reader->value)->permissions->pluck('name')->sort()->values()->all();
        expect($readerPermissions)->toBe(
            collect(PermissionEnum::cases())
                ->filter(fn (PermissionEnum $permission): bool => str_starts_with($permission->value, 'can-show-'))
                ->map(fn (PermissionEnum $permission): string => $permission->value)
                ->sort()
                ->values()
                ->all(),
        );

        expect($custom->fresh()->permissions->pluck('name')->all())
            ->toBe([PermissionEnum::CanShowUsers->value]);
    }
});
