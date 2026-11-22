<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\RoleEnum as BrainRoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\InstanceSuperAdminGroupService;
use App\Services\Authorization\PermissionsTeamContextService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->resolver = app(EffectivePermissionResolverService::class);
});

test('the resolver unites direct roles and group roles', function (): void {
    $organisation = Organisation::factory()->create();
    app(PermissionsTeamContextService::class)->setOrganisation($organisation->id);

    $directRole = Role::query()->create(['name' => 'direct-editor', 'guard_name' => 'web', 'organisation_id' => null]);
    $directRole->syncPermissions([PermissionEnum::CanEditUsers->value]);
    $groupRole = Role::query()->create(['name' => 'group-viewer', 'guard_name' => 'web', 'organisation_id' => null]);
    $groupRole->syncPermissions([PermissionEnum::CanShowGroups->value]);

    $group = UserGroup::factory()->create(['name' => 'Editors']);
    $group->roles()->attach($groupRole);
    $group->brainAccess()->create([
        'organisation_id' => $organisation->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisation->id,
        'role' => BrainRoleEnum::Member->value,
        'max_visibility' => null,
        'areas' => [],
        'path_prefixes' => [],
    ]);
    $user = assignOrganisationRole(User::factory()->create(), $directRole, $organisation->id, withPerimeter: false);
    $user->groups()->attach($group);

    expect($this->resolver->permissionsFor($user))->toBe([PermissionEnum::CanEditUsers->value, PermissionEnum::CanShowGroups->value])
        ->and($this->resolver->roleNamesFor($user))->toBe(['direct-editor', 'group-viewer'])
        ->and($this->resolver->effectiveRoleIds($user))->toEqualCanonicalizing([$directRole->id, $groupRole->id])
        ->and($this->resolver->hasPermission($user, PermissionEnum::CanDeleteGroups->value))->toBeFalse();
});

test('a permission inherited through a group passes the gate, the route middleware and the shared props', function (): void {
    $organisation = Organisation::factory()->create();
    $group = UserGroup::factory()->create();
    $group->roles()->attach(Role::findByName(RoleEnum::Reader->value));
    $group->brainAccess()->create([
        'organisation_id' => $organisation->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisation->id,
        'role' => BrainRoleEnum::Member->value,
        'max_visibility' => null,
        'areas' => [],
        'path_prefixes' => [],
    ]);
    $user = User::factory()->create();
    $user->groups()->attach($group);
    app(GrantOrganisationAccessAction::class)->execute($user, $organisation, BrainRoleEnum::Member);
    app(PermissionsTeamContextService::class)->setOrganisation($organisation->id, $user);

    expect($user->roles()->count())->toBe(0)
        ->and(Gate::forUser($user)->allows(PermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and(Gate::forUser($user)->allows(PermissionEnum::CanDeleteUsers->value))->toBeFalse();

    $this->withoutVite()->actingAs($user)->get(route('users.index'))->assertOk();
    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.roleNames', [RoleEnum::Reader->value])
            ->where('auth.permissions', fn ($permissions): bool => collect($permissions)->contains(PermissionEnum::CanShowUsers->value)));
});

test('a soft-deleted instance super-admin group grants nothing', function (): void {
    $user = grantInstanceSuperAdmin(User::factory()->create());
    $group = UserGroup::query()->where('name', InstanceSuperAdminGroupService::GROUP_NAME)->sole();

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
