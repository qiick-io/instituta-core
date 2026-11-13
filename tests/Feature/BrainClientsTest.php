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
        ->get(route('brain.clients.index'))
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
        ->get(route('brain.clients.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('brain/clients')
            ->where('is_owner', false)
            ->where('clients', []));
});

it('creates a client company for organisation owners', function (): void {
    ['org' => $org, 'user' => $user] = clientsOwnerContext();

    $this->actingAs($user)
        ->post(route('brain.clients.store'), [
            'name' => 'New Client Co',
            'slug' => 'new-client-co',
        ])
        ->assertRedirect(route('brain.clients.index'));

    $client = Company::query()
        ->where('organisation_id', $org->id)
        ->where('slug', 'new-client-co')
        ->first();

    expect($client)->not->toBeNull()
        ->and($client->name)->toBe('New Client Co')
        ->and($client->kind)->toBe(CompanyKindEnum::Client);

    $this->assertDatabaseHas('activity_log', [
        'event' => 'client.created',
        'subject_id' => $client->id,
    ]);
});

it('rejects client create outside owner perimeter and for non-owners', function (): void {
    $org = Organisation::factory()->create();
    $member = User::factory()->create();
    $principal = Principal::factory()->create([
        'organisation_id' => $org->id,
        'user_id' => $member->id,
        'type' => 'person',
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Member,
        'max_visibility' => VisibilityEnum::Internal,
    ]);

    $this->actingAs($member)
        ->post(route('brain.clients.store'), [
            'name' => 'Forbidden',
            'slug' => 'forbidden-client',
        ])
        ->assertForbidden();

    expect(Company::query()->where('slug', 'forbidden-client')->exists())->toBeFalse();
});

it('updates an owned client company and blocks cross-tenant edits', function (): void {
    ['org' => $org, 'user' => $user] = clientsOwnerContext();

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
        'name' => 'Before',
        'slug' => 'before-slug',
    ]);

    $otherOrg = Organisation::factory()->create();
    $foreign = Company::factory()->client()->create([
        'organisation_id' => $otherOrg->id,
        'name' => 'Foreign',
        'slug' => 'foreign-client',
    ]);

    $this->actingAs($user)
        ->put(route('brain.clients.update', $client), [
            'name' => 'After Name',
            'slug' => 'after-slug',
        ])
        ->assertRedirect(route('brain.clients.index'));

    $client->refresh();
    expect($client->name)->toBe('After Name')
        ->and($client->slug)->toBe('after-slug');

    $this->actingAs($user)
        ->put(route('brain.clients.update', $foreign), [
            'name' => 'Hijacked',
            'slug' => 'hijacked',
        ])
        ->assertForbidden();

    $foreign->refresh();
    expect($foreign->name)->toBe('Foreign')
        ->and($foreign->slug)->toBe('foreign-client');
});

it('rejects invalid or duplicate client slugs', function (): void {
    ['org' => $org, 'user' => $user] = clientsOwnerContext();

    Company::factory()->client()->create([
        'organisation_id' => $org->id,
        'slug' => 'taken-slug',
    ]);

    $this->actingAs($user)
        ->post(route('brain.clients.store'), [
            'name' => 'Bad Slug',
            'slug' => 'Bad Slug!',
        ])
        ->assertSessionHasErrors('slug');

    $this->actingAs($user)
        ->post(route('brain.clients.store'), [
            'name' => 'Taken',
            'slug' => 'taken-slug',
        ])
        ->assertSessionHasErrors('slug');
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
