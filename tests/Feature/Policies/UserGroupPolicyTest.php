<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum as BrainRoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\PermissionsTeamContextService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    $this->organisation = Organisation::factory()->create();
    $this->superAdmin = grantInstanceSuperAdmin(User::factory()->create());
    app(PermissionsTeamContextService::class)->setOrganisation($this->organisation->id);

    $this->group = UserGroup::factory()->create([
        'name' => 'Editors',
        'organisation_id' => $this->organisation->id,
    ]);
    $this->superAdminGroup = UserGroup::factory()->create([
        'name' => 'Owners',
        'organisation_id' => $this->organisation->id,
    ]);
    $this->superAdminGroup->roles()->sync([Role::findByName(RoleEnum::SuperAdmin->value)->id]);

    $managerRole = Role::query()->create(['name' => 'groups-manager', 'guard_name' => 'web', 'organisation_id' => null]);
    $managerRole->syncPermissions([
        PermissionEnum::CanShowGroups->value,
        PermissionEnum::CanCreateGroups->value,
        PermissionEnum::CanEditGroups->value,
        PermissionEnum::CanDeleteGroups->value,
        PermissionEnum::CanRestoreGroups->value,
        PermissionEnum::CanForceDeleteGroups->value,
    ]);
    $this->manager = assignOrganisationRole(User::factory()->create(), $managerRole, $this->organisation->id);
    $this->outsider = User::factory()->create();
});

test('class abilities follow the route permission', function (string $ability): void {
    expect(Gate::forUser($this->manager)->allows($ability, UserGroup::class))->toBeTrue()
        ->and(Gate::forUser($this->outsider)->allows($ability, UserGroup::class))->toBeFalse()
        ->and(Gate::forUser($this->superAdmin)->allows($ability, UserGroup::class))->toBeTrue()
        ->and(Gate::forUser(null)->allows($ability, UserGroup::class))->toBeFalse();
})->with(['viewAny', 'create']);

test('record abilities follow the route permission', function (string $ability): void {
    expect(Gate::forUser($this->manager)->allows($ability, $this->group))->toBeTrue()
        ->and(Gate::forUser($this->outsider)->allows($ability, $this->group))->toBeFalse()
        ->and(Gate::forUser($this->superAdmin)->allows($ability, $this->group))->toBeTrue();
})->with(['update', 'delete', 'restore', 'forceDelete']);

test('only a super-admin may touch a group that grants super-admin', function (string $ability): void {
    expect(Gate::forUser($this->manager)->allows($ability, $this->superAdminGroup))->toBeFalse()
        ->and(Gate::forUser($this->superAdmin)->allows($ability, $this->superAdminGroup))->toBeTrue();
})->with(['update', 'delete', 'restore', 'forceDelete']);

test('a group manager inheriting the permissions through a group passes too', function (): void {
    $managers = UserGroup::factory()->create([
        'name' => 'Managers',
        'organisation_id' => $this->organisation->id,
    ]);
    $managers->roles()->sync([Role::findByName('groups-manager')->id]);
    $managers->brainAccess()->create([
        'organisation_id' => $this->organisation->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $this->organisation->id,
        'role' => BrainRoleEnum::Member->value,
        'max_visibility' => null,
        'areas' => [],
        'path_prefixes' => [],
    ]);
    $viaGroup = User::factory()->create();
    $viaGroup->groups()->sync([$managers->id]);
    app(PermissionsTeamContextService::class)->setOrganisation($this->organisation->id, $viaGroup);

    expect(Gate::forUser($viaGroup)->allows('update', $this->group))->toBeTrue()
        ->and(Gate::forUser($viaGroup)->allows('update', $this->superAdminGroup))->toBeFalse();
});
