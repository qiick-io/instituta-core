<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->resolver = app(EffectivePermissionResolverService::class);
});

test('the resolver unites direct roles and group roles', function (): void {
    $directRole = Role::query()->create(['name' => 'direct-editor', 'guard_name' => 'web']);
    $directRole->syncPermissions([PermissionEnum::CanEditUsers->value]);
    $groupRole = Role::query()->create(['name' => 'group-viewer', 'guard_name' => 'web']);
    $groupRole->syncPermissions([PermissionEnum::CanShowGroups->value]);

    $group = UserGroup::factory()->create(['name' => 'Editors']);
    $group->roles()->attach($groupRole);
    $user = User::factory()->create()->assignRole($directRole);
    $user->groups()->attach($group);

    expect($this->resolver->permissionsFor($user))->toBe([PermissionEnum::CanEditUsers->value, PermissionEnum::CanShowGroups->value])
        ->and($this->resolver->roleNamesFor($user))->toBe(['direct-editor', 'group-viewer'])
        ->and($this->resolver->effectiveRoleIds($user))->toEqualCanonicalizing([$directRole->id, $groupRole->id])
        ->and($this->resolver->hasPermission($user, PermissionEnum::CanDeleteGroups->value))->toBeFalse();
});

test('a permission inherited through a group passes the gate, the route middleware and the shared props', function (): void {
    $group = UserGroup::factory()->create();
    $group->roles()->attach(Role::findByName(RoleEnum::Reader->value));
    $user = User::factory()->create();
    $user->groups()->attach($group);

    expect($user->roles()->count())->toBe(0)
        ->and(Gate::forUser($user)->allows(PermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and(Gate::forUser($user)->allows(PermissionEnum::CanDeleteUsers->value))->toBeFalse();

    $this->withoutVite()->actingAs($user)->get(route('users.index'))->assertOk();
    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.roleNames', [RoleEnum::Reader->value])
            ->where('auth.permissions', fn ($permissions): bool => collect($permissions)->contains(PermissionEnum::CanShowUsers->value)));
});

test('a soft-deleted group or a left group grants nothing', function (): void {
    $group = UserGroup::factory()->create();
    $group->roles()->attach(Role::findByName(RoleEnum::SuperAdmin->value));
    $user = User::factory()->create();
    $user->groups()->attach($group);

    expect($this->resolver->isSuperAdmin($user))->toBeTrue()
        ->and($this->resolver->permissionsFor($user))->toBe(PermissionEnum::values());

    $group->delete();
    $this->resolver->forget($user);

    expect($this->resolver->isSuperAdmin($user))->toBeFalse()
        ->and($this->resolver->permissionsFor($user))->toBe([]);

    $group->restore();
    $user->groups()->detach($group);
    $this->resolver->forget($user);

    expect($this->resolver->roleNamesFor($user))->toBe([]);
});
