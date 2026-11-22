<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

/**
 * A user whose only access is a custom role with the given permissions, in a known organisation.
 *
 * @param  list<PermissionEnum>  $permissions
 * @return array{0: User, 1: string}
 */
function groupManager(array $permissions): array
{
    $organisation = Organisation::factory()->create();
    $role = Role::query()->firstOrCreate(['name' => 'groups-manager', 'guard_name' => 'web', 'organisation_id' => null]);
    $role->syncPermissions(array_map(fn (PermissionEnum $permission): string => $permission->value, $permissions));

    return [
        assignOrganisationRole(User::factory()->create(), $role, $organisation->id),
        $organisation->id,
    ];
}

/**
 * @return list<PermissionEnum>
 */
function allGroupPermissions(): array
{
    return [
        PermissionEnum::CanShowGroups,
        PermissionEnum::CanCreateGroups,
        PermissionEnum::CanEditGroups,
        PermissionEnum::CanDeleteGroups,
        PermissionEnum::CanRestoreGroups,
        PermissionEnum::CanForceDeleteGroups,
    ];
}

it('sends guests to the login page', function (): void {
    $this->get(route('groups.index'))->assertRedirect(route('login'));
});

it('forbids users without group permissions', function (): void {
    $user = User::factory()->create();
    $group = UserGroup::factory()->create();

    $this->actingAs($user)->get(route('groups.index'))->assertForbidden();
    $this->actingAs($user)->post(route('groups.store'), ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($user)->put(route('groups.update', $group), ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($user)->delete(route('groups.destroy', $group))->assertForbidden();
    $this->actingAs($user)->post(route('groups.bulk-actions'), ['action' => 'delete', 'ids' => [$group->id]])->assertForbidden();
});

it('lists groups with search, members and the trash filter', function (): void {
    [$manager, $organisationId] = groupManager([PermissionEnum::CanShowGroups]);
    $member = User::factory()->create();
    $editors = UserGroup::factory()->create(['name' => 'Editors', 'organisation_id' => $organisationId]);
    $editors->users()->sync([$member->id]);
    UserGroup::factory()->create(['name' => 'Support team', 'organisation_id' => $organisationId]);

    $this->actingAs($manager)
        ->get(route('groups.index', ['search' => 'editor']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/groups/index')
            ->has('groups.data', 1)
            ->where('groups.data.0.name', 'Editors')
            ->where('groups.data.0.user_ids', [$member->public_id])
            ->has('groups.data.0.users', 1)
            ->where('filters.search', 'editor')
            ->where('filters.trashed', false));
});

it('sorts groups by name and update time', function (): void {
    [$manager, $organisationId] = groupManager([PermissionEnum::CanShowGroups]);
    $zebra = UserGroup::factory()->create(['name' => 'Zebra', 'organisation_id' => $organisationId, 'updated_at' => now()->subDay()]);
    $alpha = UserGroup::factory()->create(['name' => 'Alpha', 'organisation_id' => $organisationId, 'updated_at' => now()]);

    $this->actingAs($manager)->get(route('groups.index', ['sort' => 'name', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.data.0.id', $alpha->id)
            ->where('groups.data.1.id', $zebra->id)
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'asc'));

    $this->actingAs($manager)->get(route('groups.index', ['sort' => 'updated_at', 'direction' => 'desc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.data.0.id', $alpha->id)
            ->where('filters.sort', 'updated_at'));
});

it('returns json for the group pickers', function (): void {
    [$manager, $organisationId] = groupManager([PermissionEnum::CanShowGroups]);
    $group = UserGroup::factory()->create(['name' => 'Picker Group', 'organisation_id' => $organisationId]);

    $this->actingAs($manager)
        ->getJson(route('groups.index', ['search' => 'Picker Group']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $group->id)
        ->assertJsonPath('data.0.name', 'Picker Group')
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('creates, updates and soft deletes a group with members and roles', function (): void {
    $member = User::factory()->create();
    $reader = Role::findByName(RoleEnum::Reader->value);
    $organisation = Organisation::factory()->create();
    $this->actingAs(assignOrganisationRole(User::factory()->create(), RoleEnum::Admin->value, $organisation->id));

    $this->post(route('groups.store'), [
        'name' => 'Content',
        'description' => 'Content editors',
        'user_ids' => [$member->public_id],
        'role_ids' => [$reader->id],
    ])->assertRedirect(route('groups.index'));

    $group = UserGroup::query()->where('name', 'Content')->sole();

    expect($group->organisation_id)->toBe($organisation->id)
        ->and($group->is_instance)->toBeFalse()
        ->and($group->users()->pluck('users.id')->all())->toBe([$member->id])
        ->and($group->roles()->pluck('roles.id')->all())->toBe([$reader->id]);

    $this->put(route('groups.update', $group), [
        'name' => 'Content updated',
        'description' => 'Updated',
        'user_ids' => [],
        'role_ids' => [$reader->id],
    ])->assertRedirect(route('groups.index'));

    $group->refresh();

    expect($group->name)->toBe('Content updated')
        ->and($group->users()->count())->toBe(0);

    $this->delete(route('groups.destroy', $group))->assertRedirect(route('groups.index'));

    expect($group->fresh()?->trashed())->toBeTrue();
});

it('keeps members and roles when the update omits them', function (): void {
    $organisation = Organisation::factory()->create();
    $member = User::factory()->create();
    $reader = Role::findByName(RoleEnum::Reader->value);
    $group = UserGroup::factory()->create([
        'name' => 'Stable',
        'description' => 'Before',
        'organisation_id' => $organisation->id,
    ]);
    $group->users()->sync([$member->id]);
    $group->roles()->sync([$reader->id]);

    $this->actingAs(assignOrganisationRole(User::factory()->create(), RoleEnum::Admin->value, $organisation->id))
        ->put(route('groups.update', $group), ['name' => 'Stable', 'description' => 'After'])
        ->assertRedirect(route('groups.index'));

    expect($group->fresh()?->description)->toBe('After')
        ->and($group->users()->pluck('users.id')->all())->toBe([$member->id])
        ->and($group->roles()->pluck('roles.id')->all())->toBe([$reader->id]);
});

it('soft deletes, restores, force deletes and bulk manages groups', function (): void {
    [$manager, $organisationId] = groupManager(allGroupPermissions());
    $this->actingAs($manager);
    $victim = UserGroup::factory()->create(['name' => 'Victim', 'organisation_id' => $organisationId]);
    $bulkGroups = UserGroup::factory()->count(2)->create(['organisation_id' => $organisationId]);
    $bulkIds = $bulkGroups->pluck('id')->all();

    $this->delete(route('groups.destroy', $victim))->assertRedirect(route('groups.index'));
    expect($victim->fresh()?->trashed())->toBeTrue();

    $this->get(route('groups.index', ['trashed' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.trashed', true)
            ->has('groups.data', 1)
            ->where('groups.data.0.name', 'Victim'));

    $this->post(route('groups.restore', $victim))->assertRedirect(route('groups.index', ['trashed' => 1]));
    expect($victim->fresh()?->trashed())->toBeFalse();

    $victim->delete();
    $this->delete(route('groups.force-delete', $victim))->assertRedirect(route('groups.index', ['trashed' => 1]));
    expect(UserGroup::withTrashed()->find($victim->id))->toBeNull();

    $this->post(route('groups.bulk-actions'), ['action' => 'delete', 'ids' => $bulkIds])->assertRedirect();
    expect(UserGroup::query()->whereIn('id', $bulkIds)->count())->toBe(0);

    $this->post(route('groups.bulk-actions'), ['action' => 'restore', 'ids' => $bulkIds])->assertRedirect();
    expect(UserGroup::query()->whereIn('id', $bulkIds)->count())->toBe(2);

    // force_delete only applies to trashed groups.
    $this->post(route('groups.bulk-actions'), ['action' => 'force_delete', 'ids' => $bulkIds])->assertRedirect();
    expect(UserGroup::query()->whereIn('id', $bulkIds)->count())->toBe(2);

    UserGroup::query()->whereIn('id', $bulkIds)->delete();
    $this->post(route('groups.bulk-actions'), ['action' => 'force_delete', 'ids' => $bulkIds])->assertRedirect();
    expect(UserGroup::withTrashed()->whereIn('id', $bulkIds)->count())->toBe(0);
});

it('rejects roles the group manager could not grant', function (): void {
    $member = User::factory()->create();
    [$manager, $organisationId] = groupManager(allGroupPermissions());
    $superAdminGroup = UserGroup::factory()->create(['organisation_id' => $organisationId]);
    $superAdminGroup->roles()->sync([Role::findByName(RoleEnum::SuperAdmin->value)->id]);

    $this->actingAs($manager)
        ->post(route('groups.store'), [
            'name' => 'Escalation',
            'user_ids' => [$manager->public_id, $member->public_id],
            'role_ids' => [Role::findByName(RoleEnum::Admin->value)->id],
        ])
        ->assertSessionHasErrors('role_ids.0');

    expect(UserGroup::query()->where('name', 'Escalation')->exists())->toBeFalse();

    $this->actingAs($manager)
        ->put(route('groups.update', $superAdminGroup), ['name' => 'Mine now', 'user_ids' => [$manager->public_id]])
        ->assertForbidden();

    $this->actingAs($manager)
        ->post(route('groups.bulk-actions'), ['action' => 'delete', 'ids' => [$superAdminGroup->id]])
        ->assertSessionHasErrors('ids');

    expect($superAdminGroup->fresh()?->trashed())->toBeFalse();
});

it('assigns user groups from the user form', function (): void {
    $organisation = Organisation::factory()->create();
    $group = UserGroup::factory()->create(['organisation_id' => $organisation->id]);
    $group->roles()->sync([Role::findByName(RoleEnum::Reader->value)->id]);
    $user = assignOrganisationRole(User::factory()->create(), RoleEnum::Reader->value, $organisation->id);

    $this->actingAs(assignOrganisationRole(User::factory()->create(), RoleEnum::Admin->value, $organisation->id))
        ->put(route('users.update', $user), [
            'first_name' => 'Grace',
            'email' => 'grace@example.com',
            'roles' => [RoleEnum::Reader->value],
            'is_active' => true,
            'group_ids' => [$group->id],
        ])
        ->assertRedirect(route('users.index'));

    expect($user->groups()->pluck('user_groups.id')->all())->toBe([$group->id]);
});

it('keeps the user form from joining groups with roles the actor cannot grant', function (): void {
    $organisation = Organisation::factory()->create();
    $superAdminGroup = UserGroup::factory()->create(['organisation_id' => $organisation->id]);
    $superAdminGroup->roles()->sync([Role::findByName(RoleEnum::SuperAdmin->value)->id]);
    $user = assignOrganisationRole(User::factory()->create(), RoleEnum::Reader->value, $organisation->id);

    $this->actingAs(assignOrganisationRole(User::factory()->create(), RoleEnum::Admin->value, $organisation->id))
        ->put(route('users.update', $user), [
            'first_name' => 'Grace',
            'email' => 'grace@example.com',
            'roles' => [RoleEnum::Reader->value],
            'is_active' => true,
            'group_ids' => [$superAdminGroup->id],
        ])
        ->assertSessionHasErrors('group_ids');

    expect($user->groups()->count())->toBe(0);
});

it('protects the last super-admin when it holds the role through a group', function (): void {
    $owner = grantInstanceSuperAdmin(User::factory()->create());
    $owners = UserGroup::query()->where('is_instance', true)->sole();

    $this->actingAs($owner)
        ->put(route('groups.update', $owners), ['name' => $owners->name, 'user_ids' => []])
        ->assertSessionHasErrors('user_ids');

    $this->actingAs($owner)
        ->delete(route('groups.destroy', $owners))
        ->assertSessionHasErrors('ids');

    expect($owners->users()->pluck('users.id')->all())->toBe([$owner->id])
        ->and($owners->fresh()?->trashed())->toBeFalse();
});
