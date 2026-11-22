<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
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
 * A user whose only access is a custom role with the given permissions.
 *
 * @param  list<PermissionEnum>  $permissions
 */
function groupManager(array $permissions): User
{
    $role = Role::query()->firstOrCreate(['name' => 'groups-manager', 'guard_name' => 'web', 'organisation_id' => null]);
    $role->syncPermissions(array_map(fn (PermissionEnum $permission): string => $permission->value, $permissions));

    return assignOrganisationRole(User::factory()->create(), $role);
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
    $member = User::factory()->create();
    $editors = UserGroup::factory()->create(['name' => 'Editors']);
    $editors->users()->sync([$member->id]);
    UserGroup::factory()->create(['name' => 'Support team']);

    $this->actingAs(groupManager([PermissionEnum::CanShowGroups]))
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
    $zebra = UserGroup::factory()->create(['name' => 'Zebra', 'updated_at' => now()->subDay()]);
    $alpha = UserGroup::factory()->create(['name' => 'Alpha', 'updated_at' => now()]);
    $manager = groupManager([PermissionEnum::CanShowGroups]);

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
    $group = UserGroup::factory()->create(['name' => 'Picker Group']);

    $this->actingAs(groupManager([PermissionEnum::CanShowGroups]))
        ->getJson(route('groups.index', ['search' => 'Picker Group']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $group->id)
        ->assertJsonPath('data.0.name', 'Picker Group')
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('creates, updates and soft deletes a group with members and roles', function (): void {
    $member = User::factory()->create();
    $reader = Role::findByName(RoleEnum::Reader->value);
    $this->actingAs(assignOrganisationRole(User::factory()->create(), RoleEnum::Admin->value));

    $this->post(route('groups.store'), [
        'name' => 'Content',
        'description' => 'Content editors',
        'user_ids' => [$member->public_id],
        'role_ids' => [$reader->id],
    ])->assertRedirect(route('groups.index'));

    $group = UserGroup::query()->where('name', 'Content')->sole();

    expect($group->users()->pluck('users.id')->all())->toBe([$member->id])
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
    $member = User::factory()->create();
    $reader = Role::findByName(RoleEnum::Reader->value);
    $group = UserGroup::factory()->create(['name' => 'Stable', 'description' => 'Before']);
    $group->users()->sync([$member->id]);
    $group->roles()->sync([$reader->id]);

    $this->actingAs(assignOrganisationRole(User::factory()->create(), RoleEnum::Admin->value))
        ->put(route('groups.update', $group), ['name' => 'Stable', 'description' => 'After'])
        ->assertRedirect(route('groups.index'));

    expect($group->fresh()?->description)->toBe('After')
        ->and($group->users()->pluck('users.id')->all())->toBe([$member->id])
        ->and($group->roles()->pluck('roles.id')->all())->toBe([$reader->id]);
});

it('soft deletes, restores, force deletes and bulk manages groups', function (): void {
    $this->actingAs(groupManager(allGroupPermissions()));
    $victim = UserGroup::factory()->create(['name' => 'Victim']);
    $bulkGroups = UserGroup::factory()->count(2)->create();
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
    $manager = groupManager(allGroupPermissions());
    $superAdminGroup = UserGroup::factory()->create();
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
    $group = UserGroup::factory()->create();
    $group->roles()->sync([Role::findByName(RoleEnum::Reader->value)->id]);
    $user = assignOrganisationRole(User::factory()->create(), RoleEnum::Reader->value);

    $this->actingAs(assignOrganisationRole(User::factory()->create(), RoleEnum::Admin->value))
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
    $superAdminGroup = UserGroup::factory()->create();
    $superAdminGroup->roles()->sync([Role::findByName(RoleEnum::SuperAdmin->value)->id]);
    $user = assignOrganisationRole(User::factory()->create(), RoleEnum::Reader->value);

    $this->actingAs(assignOrganisationRole(User::factory()->create(), RoleEnum::Admin->value))
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
    $owner = User::factory()->create();
    $owners = UserGroup::factory()->create(['name' => 'Owners']);
    $owners->roles()->sync([Role::findByName(RoleEnum::SuperAdmin->value)->id]);
    $owners->users()->sync([$owner->id]);

    $this->actingAs($owner)
        ->put(route('groups.update', $owners), ['name' => 'Owners', 'user_ids' => []])
        ->assertSessionHasErrors('user_ids');

    $this->actingAs($owner)
        ->delete(route('groups.destroy', $owners))
        ->assertSessionHasErrors('ids');

    expect($owners->users()->pluck('users.id')->all())->toBe([$owner->id])
        ->and($owners->fresh()?->trashed())->toBeFalse();
});
