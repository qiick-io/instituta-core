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
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\InstanceSuperAdminGroupService;
use App\Services\Authorization\PermissionsTeamContextService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * With Spatie teams on, organisation-scoped roles must not leak across organisations,
 * while instance super-admin (group membership) remains effective everywhere.
 */
beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

test('roles assigned in organisation A are invisible in organisation B', function (): void {
    $organisationA = Organisation::factory()->create();
    $organisationB = Organisation::factory()->create();
    $user = User::factory()->create();
    $teamContext = app(PermissionsTeamContextService::class);

    assignOrganisationRole($user, RoleEnum::Admin, $organisationA->id);

    $teamContext->setOrganisation($organisationA->id, $user);
    expect($user->hasRole(RoleEnum::Admin->value))->toBeTrue()
        ->and(Gate::forUser($user)->allows(PermissionEnum::CanShowUsers->value))->toBeTrue();

    $teamContext->setOrganisation($organisationB->id, $user);
    expect($user->hasRole(RoleEnum::Admin->value))->toBeFalse()
        ->and(Gate::forUser($user)->allows(PermissionEnum::CanShowUsers->value))->toBeFalse();
});

test('instance super-admin works in every organisation without a team pivot row', function (): void {
    $organisationA = Organisation::factory()->create();
    $organisationB = Organisation::factory()->create();
    $user = grantInstanceSuperAdmin(User::factory()->create());
    $teamContext = app(PermissionsTeamContextService::class);
    $resolver = app(EffectivePermissionResolverService::class);

    expect($resolver->isSuperAdmin($user))->toBeTrue();

    foreach ([$organisationA->id, $organisationB->id] as $organisationId) {
        $teamContext->setOrganisation($organisationId);
        expect(Gate::forUser($user)->allows('non-existent-ability'))->toBeTrue()
            ->and(Gate::forUser($user)->allows(PermissionEnum::CanDeleteUsers->value))->toBeTrue();
    }

    expect(
        $user->roles()->where('name', RoleEnum::SuperAdmin->value)->exists()
    )->toBeFalse();
});

/**
 * Review B1: an org-A admin group must not grant dashboard permissions while the team is B.
 */
test('a group admin role in organisation A does not grant permissions in organisation B', function (): void {
    $organisationA = Organisation::factory()->create();
    $organisationB = Organisation::factory()->create();
    $user = User::factory()->create();
    $teamContext = app(PermissionsTeamContextService::class);

    $group = UserGroup::factory()->create([
        'name' => 'Org A admins',
        'organisation_id' => $organisationA->id,
    ]);
    $group->roles()->attach(Role::findByName(RoleEnum::Admin->value));
    $group->brainAccess()->create([
        'organisation_id' => $organisationA->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisationA->id,
        'role' => BrainRoleEnum::Member->value,
        'max_visibility' => null,
        'areas' => [],
        'path_prefixes' => [],
    ]);
    $user->groups()->attach($group);

    $teamContext->setOrganisation($organisationB->id, $user);
    expect(Gate::forUser($user)->allows(PermissionEnum::CanDeleteUsers->value))->toBeFalse()
        ->and(Gate::forUser($user)->allows(PermissionEnum::CanShowUsers->value))->toBeFalse();

    $teamContext->setOrganisation($organisationA->id, $user);
    expect(Gate::forUser($user)->allows(PermissionEnum::CanDeleteUsers->value))->toBeTrue();
});

test('a group with an admin role and no organisation grants nothing under a team', function (): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->create();
    $teamContext = app(PermissionsTeamContextService::class);

    $group = UserGroup::factory()->create(['name' => 'Orphan admins']);
    $group->roles()->attach(Role::findByName(RoleEnum::Admin->value));
    $user->groups()->attach($group);

    $teamContext->setOrganisation($organisation->id, $user);
    expect(Gate::forUser($user)->allows(PermissionEnum::CanDeleteUsers->value))->toBeFalse();
});

test('only an is_instance group grants isSuperAdmin, not a role on an organisation group', function (): void {
    $resolver = app(EffectivePermissionResolverService::class);
    $user = User::factory()->create();

    $imposter = UserGroup::factory()->create(['name' => 'Org owners with super-admin role']);
    $imposter->roles()->attach(Role::findByName(RoleEnum::SuperAdmin->value));
    $user->groups()->attach($imposter);

    expect($resolver->isSuperAdmin($user))->toBeFalse()
        ->and($imposter->is_instance)->toBeFalse();

    grantInstanceSuperAdmin($user);
    $resolver->forget($user);

    $instanceGroup = UserGroup::query()->where('is_instance', true)->sole();

    expect($resolver->isSuperAdmin($user))->toBeTrue()
        ->and($instanceGroup->organisation_id)->toBeNull()
        ->and($instanceGroup->name)->toBe(InstanceSuperAdminGroupService::GROUP_NAME);
});

test('an organisation A admin cannot update a group that belongs to organisation B', function (): void {
    $organisationA = Organisation::factory()->create();
    $organisationB = Organisation::factory()->create();
    $adminA = assignOrganisationRole(User::factory()->create(), RoleEnum::Admin, $organisationA->id);
    $groupB = UserGroup::factory()->create([
        'name' => 'Org B editors',
        'organisation_id' => $organisationB->id,
    ]);
    $groupB->roles()->attach(Role::findByName(RoleEnum::Admin->value));

    app(PermissionsTeamContextService::class)->setOrganisation($organisationA->id, $adminA);

    expect(Gate::forUser($adminA)->allows('update', $groupB))->toBeFalse()
        ->and(Gate::forUser($adminA)->allows('delete', $groupB))->toBeFalse();

    $this->actingAs($adminA)
        ->put(route('groups.update', $groupB), [
            'name' => 'Hijacked',
            'user_ids' => [$adminA->public_id],
            'role_ids' => [Role::findByName(RoleEnum::Admin->value)->id],
        ])
        ->assertForbidden();

    expect($groupB->fresh()->name)->toBe('Org B editors')
        ->and($groupB->users()->count())->toBe(0);
});

test('the reserved instance super-admin name cannot be claimed when creating or renaming a group', function (): void {
    $organisation = Organisation::factory()->create();
    $admin = assignOrganisationRole(User::factory()->create(), RoleEnum::Admin, $organisation->id);

    $this->actingAs($admin)
        ->post(route('groups.store'), ['name' => InstanceSuperAdminGroupService::GROUP_NAME])
        ->assertSessionHasErrors('name');

    $group = UserGroup::factory()->create([
        'name' => 'Editors',
        'organisation_id' => $organisation->id,
    ]);

    $this->actingAs($admin)
        ->put(route('groups.update', $group), ['name' => InstanceSuperAdminGroupService::GROUP_NAME])
        ->assertSessionHasErrors('name');

    expect(UserGroup::query()->where('name', InstanceSuperAdminGroupService::GROUP_NAME)->where('is_instance', false)->exists())->toBeFalse();
});
