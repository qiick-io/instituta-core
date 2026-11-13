<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\CompanyKindEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @return array{org: Organisation, user: User, principal: Principal}
 */
function clientsOwnerContext(): array
{
    $org = Organisation::factory()->create();
    $user = User::factory()->create();
    $principal = Principal::factory()->create([
        'organisation_id' => $org->id,
        'user_id' => $user->id,
        'type' => 'person',
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    return compact('org', 'user', 'principal');
}

it('lists only client companies for organisation owners', function (): void {
    ['org' => $org, 'user' => $user] = clientsOwnerContext();

    Company::factory()->create([
        'organisation_id' => $org->id,
        'kind' => CompanyKindEnum::Own,
        'name' => 'Own Co',
        'slug' => 'own-co',
    ]);

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
        'name' => 'Acme Client',
        'slug' => 'acme-client',
    ]);
    $product = Product::factory()->create([
        'company_id' => $client->id,
        'name' => 'Portal',
        'slug' => 'portal',
    ]);

    $otherOrg = Organisation::factory()->create();
    Company::factory()->client()->create([
        'organisation_id' => $otherOrg->id,
        'name' => 'Other Client',
        'slug' => 'other-client',
    ]);

    $this->actingAs($user)
        ->get(route('brain.clients'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('brain/clients')
            ->where('is_owner', true)
            ->has('clients', 1)
            ->where('clients.0.id', $client->id)
            ->where('clients.0.name', 'Acme Client')
            ->where('clients.0.products.0.id', $product->id));
});

it('hides the clients list from non-owners', function (): void {
    $org = Organisation::factory()->create();
    $user = User::factory()->create();
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

    Company::factory()->client()->create([
        'organisation_id' => $org->id,
    ]);

    $this->actingAs($user)
        ->get(route('brain.clients'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('brain/clients')
            ->where('is_owner', false)
            ->where('clients', []));
});

it('includes client scopes in the shared shell payload for owners', function (): void {
    ['org' => $org, 'user' => $user] = clientsOwnerContext();

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
        'name' => 'Scoped Client',
    ]);
    $product = Product::factory()->create([
        'company_id' => $client->id,
        'name' => 'App',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('instituta.scopes')
            ->where('instituta.scopes', function ($scopes) use ($client, $product): bool {
                $rows = collect($scopes);

                return $rows->contains(fn ($scope): bool => $scope['scope_type'] === 'company'
                    && $scope['scope_id'] === $client->id
                    && ($scope['kind'] ?? null) === 'client')
                    && $rows->contains(fn ($scope): bool => $scope['scope_type'] === 'product'
                        && $scope['scope_id'] === $product->id
                        && ($scope['kind'] ?? null) === 'client');
            }));
});

it('switches the session perimeter to a client company', function (): void {
    ['org' => $org, 'user' => $user] = clientsOwnerContext();

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
    ]);

    $this->actingAs($user)
        ->post(route('brain.scope'), [
            'scope_type' => 'company',
            'scope_id' => $client->id,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('instituta.perimeter.scope_type', 'company')
            ->where('instituta.perimeter.scope_id', $client->id));
});
