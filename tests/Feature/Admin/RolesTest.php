<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\SuperAdminGuardService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

function userWithRole(RoleEnum|string $role): User
{
    return User::factory()->create()->assignRole($role instanceof RoleEnum ? $role->value : $role);
}

it('serves the role pages under settings', function (): void {
    expect(route('roles.index', absolute: false))->toBe('/settings/roles')
        ->and(route('roles.create', absolute: false))->toBe('/settings/roles/create');
});

it('lets a reader list roles but not write', function (): void {
    // RoleSeeder grants can-show-roles to reader.
    $user = userWithRole(RoleEnum::Reader);
    $custom = Role::query()->create(['name' => 'auditor', 'guard_name' => 'web']);

    $this->actingAs($user)->get(route('roles.index'))->assertOk();
    $this->actingAs($user)->get(route('roles.create'))->assertForbidden();
    $this->actingAs($user)->post(route('roles.store'), ['name' => 'x', 'permissions' => []])->assertForbidden();
    $this->actingAs($user)->put(route('roles.update', $custom), ['name' => 'x', 'permissions' => []])->assertForbidden();
    $this->actingAs($user)->delete(route('roles.destroy', $custom))->assertForbidden();
});

it('creates, updates and deletes a custom role', function (): void {
    $this->actingAs(userWithRole(RoleEnum::SuperAdmin));

    $this->get(route('roles.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/roles/index')->has('roles.data', 3));

    $this->post(route('roles.store'), [
        'name' => 'operator',
        'permissions' => [PermissionEnum::CanShowUsers->value, PermissionEnum::CanShowActivityLogs->value],
    ])->assertRedirect(route('roles.index'));

    $role = Role::findByName('operator');
    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe([
        PermissionEnum::CanShowActivityLogs->value,
        PermissionEnum::CanShowUsers->value,
    ]);

    $member = userWithRole('operator');
    expect($member->can(PermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and($member->can(PermissionEnum::CanDeleteUsers->value))->toBeFalse();

    $this->put(route('roles.update', $role), [
        'name' => 'runner',
        'permissions' => [PermissionEnum::CanShowUsers->value],
    ])->assertRedirect(route('roles.index'));

    expect($role->fresh()->name)->toBe('runner')
        ->and($member->fresh()->can(PermissionEnum::CanShowActivityLogs->value))->toBeFalse();

    $this->delete(route('roles.destroy', $role))->assertRedirect(route('roles.index'));

    expect(Role::query()->where('name', 'runner')->exists())->toBeFalse();
});

it('validates custom roles', function (): void {
    $this->actingAs(userWithRole(RoleEnum::SuperAdmin));
    Role::query()->create(['name' => 'operator', 'guard_name' => 'web']);

    $this->post(route('roles.store'), ['name' => 'operator', 'permissions' => []])->assertSessionHasErrors('name');
    $this->post(route('roles.store'), ['name' => 'admin', 'permissions' => []])->assertSessionHasErrors('name');
    $this->post(route('roles.store'), ['name' => 'other', 'permissions' => ['projects.delete']])->assertSessionHasErrors('permissions.0');
});

it('keeps built-in roles read-only, even for the super-admin', function (RoleEnum $builtIn): void {
    $this->actingAs(userWithRole(RoleEnum::SuperAdmin));
    $role = Role::findByName($builtIn->value);

    $this->get(route('roles.edit', $role))
        ->assertInertia(fn (Assert $page) => $page->component('admin/roles/form')->where('role.can_edit', false)->where('role.is_built_in', true));
    $this->put(route('roles.update', $role), ['name' => 'renamed', 'permissions' => []])->assertForbidden();
    $this->delete(route('roles.destroy', $role))->assertForbidden();

    expect(Role::query()->where('name', $builtIn->value)->exists())->toBeTrue();
})->with(RoleEnum::cases());

it('detects the last active super-admin', function (): void {
    $guard = app(SuperAdminGuardService::class);
    $first = userWithRole(RoleEnum::SuperAdmin);
    $admin = userWithRole(RoleEnum::Admin);

    expect($guard->isLastSuperAdmin($first))->toBeTrue()
        ->and($guard->isLastSuperAdmin($admin))->toBeFalse();

    $second = userWithRole(RoleEnum::SuperAdmin);

    expect($guard->isLastSuperAdmin($first))->toBeFalse();

    $second->forceFill(['is_active' => false])->save();

    expect($guard->isLastSuperAdmin($first))->toBeTrue()
        ->and($guard->isLastSuperAdmin($second))->toBeFalse();
});
