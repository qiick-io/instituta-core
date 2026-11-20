<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\ApiKey;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function keysSeed(): array
{
    $user = User::factory()->create();
    $org = Organisation::factory()->create(['name' => 'Keys Org']);
    $principal = Principal::factory()->create([
        'organisation_id' => $org->id,
        'user_id' => $user->id,
        'type' => 'person',
        'name' => $user->name,
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    ApiKey::factory()->create([
        'name' => 'dogfood',
        'owner_principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'key_prefix' => 'ins_test_dogfood1',
    ]);

    return compact('user', 'principal');
}

beforeEach(function (): void {
    $this->withoutVite();
});

it('lists api keys for an owner', function (): void {
    $seed = keysSeed();

    $this->actingAs($seed['user'])
        ->get(route('api-keys.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/api-keys')
            ->where('is_owner', true)
            ->has('keys', 1)
            ->where('keys.0.name', 'dogfood')
            ->where('keys.0.display_prefix', 'ins_test_dogfood1')
            ->where('keys.0.revoked', false));
});

it('hides keys from non-owners', function (): void {
    $user = User::factory()->create();
    $org = Organisation::factory()->create();
    $principal = Principal::factory()->create([
        'organisation_id' => $org->id,
        'user_id' => $user->id,
        'type' => 'person',
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Member,
        'max_visibility' => VisibilityEnum::Internal,
    ]);

    $this->actingAs($user)
        ->get(route('api-keys.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/api-keys')
            ->where('is_owner', false)
            ->has('keys', 0));
});

it('requires authentication for keys', function (): void {
    $this->get(route('api-keys.index'))->assertRedirect();
});
