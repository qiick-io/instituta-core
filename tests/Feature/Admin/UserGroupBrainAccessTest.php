<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Actions\SyncUserGroupsAction;
use App\Brain\Enums\RoleEnum as BrainRoleEnum;
use App\Enums\CompanyKindEnum;
use App\Enums\RoleEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * A group gives its members brain access (#258): grants follow membership and the group's
 * access, the widest role wins across groups, and a person's own grant is never touched.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->organisation = Organisation::factory()->create(['name' => 'Qiick']);
    $this->client = Company::factory()->create(['organisation_id' => $this->organisation->id, 'kind' => CompanyKindEnum::Client, 'name' => 'Acme']);
    $this->admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    app(GrantOrganisationAccessAction::class)->execute($this->admin, $this->organisation, BrainRoleEnum::Owner);
});

/**
 * The group grant of a user on a scope, or null.
 */
function groupGrantOf(User $user, string $scopeId): ?Grant
{
    return Grant::query()
        ->whereIn('principal_id', $user->principals()->pluck('id'))
        ->where('scope_id', $scopeId)
        ->first();
}

it('gives every member the group brain access and takes it back', function (): void {
    [$alice, $bruno] = User::factory()->count(2)->create()->all();

    $this->actingAs($this->admin)->post(route('groups.store'), [
        'name' => 'Acme team',
        'user_ids' => [$alice->id, $bruno->id],
        'brain_scope' => 'company:'.$this->client->id,
        'brain_role' => 'member',
    ])->assertRedirect();

    $group = UserGroup::query()->where('name', 'Acme team')->sole();
    $grant = groupGrantOf($alice, $this->client->id);

    expect($grant?->role)->toBe(BrainRoleEnum::Member)
        ->and($grant?->user_group_id)->toBe($group->id)
        ->and(groupGrantOf($bruno, $this->client->id))->not->toBeNull();

    // Bruno leaves: only his grant goes.
    $this->actingAs($this->admin)->put(route('groups.update', $group), [
        'name' => 'Acme team',
        'user_ids' => [$alice->id],
    ])->assertRedirect();

    expect(groupGrantOf($bruno, $this->client->id))->toBeNull()
        ->and(groupGrantOf($alice, $this->client->id))->not->toBeNull();

    // The access is withdrawn: Alice's grant goes too.
    $this->actingAs($this->admin)->put(route('groups.update', $group), [
        'name' => 'Acme team',
        'brain_scope' => '',
    ])->assertRedirect();

    expect(groupGrantOf($alice, $this->client->id))->toBeNull();
});

it('keeps a person\'s own grant and gives the widest role across groups', function (): void {
    $owner = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($owner, $this->organisation, BrainRoleEnum::Owner);
    $carla = User::factory()->create();

    foreach (['Guests' => 'guest', 'Members' => 'member'] as $name => $role) {
        $this->actingAs($this->admin)->post(route('groups.store'), [
            'name' => $name,
            'user_ids' => [$owner->id, $carla->id],
            'brain_scope' => 'organisation:'.$this->organisation->id,
            'brain_role' => $role,
        ])->assertRedirect();
    }

    $ownGrant = groupGrantOf($owner, $this->organisation->id);

    expect($ownGrant?->role)->toBe(BrainRoleEnum::Owner)
        ->and($ownGrant?->user_group_id)->toBeNull()
        ->and(groupGrantOf($carla, $this->organisation->id)?->role)->toBe(BrainRoleEnum::Member);
});

it('follows the group when it is deleted and restored, and when a user leaves it from the user form', function (): void {
    $dario = User::factory()->create();

    $this->actingAs($this->admin)->post(route('groups.store'), [
        'name' => 'Readers',
        'user_ids' => [$dario->id],
        'brain_scope' => 'organisation:'.$this->organisation->id,
        'brain_role' => 'guest',
    ])->assertRedirect();

    $group = UserGroup::query()->where('name', 'Readers')->sole();

    $this->actingAs($this->admin)->delete(route('groups.destroy', $group))->assertRedirect();
    expect(groupGrantOf($dario, $this->organisation->id))->toBeNull();

    $this->actingAs($this->admin)->post(route('groups.restore', $group))->assertRedirect();
    expect(groupGrantOf($dario, $this->organisation->id)?->role)->toBe(BrainRoleEnum::Guest);

    app(SyncUserGroupsAction::class)->execute($dario, []);
    expect(groupGrantOf($dario, $this->organisation->id))->toBeNull();
});

it('refuses a scope outside the editor\'s organisation', function (): void {
    $elsewhere = Organisation::factory()->create();

    $this->actingAs($this->admin)->post(route('groups.store'), [
        'name' => 'Outsiders',
        'brain_scope' => 'organisation:'.$elsewhere->id,
        'brain_role' => 'owner',
    ])->assertSessionHasErrors('brain_scope');
});
