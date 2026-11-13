<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
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
function clientProductsOwnerContext(): array
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

it('lists only products for the selected owned client', function (): void {
    ['org' => $org, 'user' => $user] = clientProductsOwnerContext();

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
        'name' => 'Acme',
    ]);
    $ownProduct = Product::factory()->create([
        'company_id' => $client->id,
        'name' => 'Portal',
        'slug' => 'portal',
        'inherits_from_parent' => true,
    ]);

    $otherClient = Company::factory()->client()->create([
        'organisation_id' => $org->id,
    ]);
    Product::factory()->create([
        'company_id' => $otherClient->id,
        'name' => 'Other Product',
    ]);

    $foreignOrg = Organisation::factory()->create();
    $foreignClient = Company::factory()->client()->create([
        'organisation_id' => $foreignOrg->id,
    ]);
    Product::factory()->create([
        'company_id' => $foreignClient->id,
    ]);

    $this->actingAs($user)
        ->get(route('brain.clients.products.index', $client))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('brain/clients/products')
            ->where('client.id', $client->id)
            ->has('products', 1)
            ->where('products.0.id', $ownProduct->id)
            ->where('products.0.inherits_from_parent', true));
});

it('redirects product create and edit pages to the index drawer deep links', function (): void {
    ['org' => $org, 'user' => $user] = clientProductsOwnerContext();

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
    ]);
    $product = Product::factory()->create([
        'company_id' => $client->id,
        'name' => 'Drawer Product',
        'slug' => 'drawer-product',
    ]);

    $this->actingAs($user)
        ->get(route('brain.clients.products.create', $client))
        ->assertRedirect(route('brain.clients.products.index', [
            'company' => $client,
            'new' => 1,
        ]));

    $this->actingAs($user)
        ->get(route('brain.clients.products.edit', [$client, $product]))
        ->assertRedirect(route('brain.clients.products.index', [
            'company' => $client,
            'edit' => $product->id,
        ]));
});

it('creates a product under an owned client with inherit default true', function (): void {
    ['org' => $org, 'user' => $user] = clientProductsOwnerContext();

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
    ]);

    $this->actingAs($user)
        ->post(route('brain.clients.products.store', $client), [
            'name' => 'New App',
            'slug' => 'new-app',
        ])
        ->assertRedirect(route('brain.clients.products.index', $client));

    $product = Product::query()
        ->where('company_id', $client->id)
        ->where('slug', 'new-app')
        ->first();

    expect($product)->not->toBeNull()
        ->and($product->name)->toBe('New App')
        ->and($product->inherits_from_parent)->toBeTrue();

    $this->assertDatabaseHas('activity_log', [
        'event' => 'product.created',
        'subject_id' => $product->id,
    ]);
});

it('creates an isolated product when inherits_from_parent is false', function (): void {
    ['org' => $org, 'user' => $user] = clientProductsOwnerContext();

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
    ]);

    $this->actingAs($user)
        ->post(route('brain.clients.products.store', $client), [
            'name' => 'Isolated App',
            'slug' => 'isolated-app',
            'inherits_from_parent' => false,
        ])
        ->assertRedirect(route('brain.clients.products.index', $client));

    $product = Product::query()->where('slug', 'isolated-app')->first();

    expect($product)->not->toBeNull()
        ->and($product->inherits_from_parent)->toBeFalse();
});

it('updates an owned client product and blocks cross-tenant access', function (): void {
    ['org' => $org, 'user' => $user] = clientProductsOwnerContext();

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
    ]);
    $product = Product::factory()->create([
        'company_id' => $client->id,
        'name' => 'Before',
        'slug' => 'before',
        'inherits_from_parent' => true,
    ]);

    $foreignOrg = Organisation::factory()->create();
    $foreignClient = Company::factory()->client()->create([
        'organisation_id' => $foreignOrg->id,
    ]);
    $foreignProduct = Product::factory()->create([
        'company_id' => $foreignClient->id,
        'name' => 'Foreign',
        'slug' => 'foreign',
    ]);

    $this->actingAs($user)
        ->put(route('brain.clients.products.update', [$client, $product]), [
            'name' => 'After',
            'slug' => 'after',
            'inherits_from_parent' => false,
        ])
        ->assertRedirect(route('brain.clients.products.index', $client));

    $product->refresh();
    expect($product->name)->toBe('After')
        ->and($product->slug)->toBe('after')
        ->and($product->inherits_from_parent)->toBeFalse();

    $this->actingAs($user)
        ->get(route('brain.clients.products.index', $foreignClient))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('brain.clients.products.update', [$foreignClient, $foreignProduct]), [
            'name' => 'Hijacked',
            'slug' => 'hijacked',
            'inherits_from_parent' => true,
        ])
        ->assertForbidden();

    $foreignProduct->refresh();
    expect($foreignProduct->name)->toBe('Foreign');
});

it('rejects product create under another client or for non-owners', function (): void {
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

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
    ]);

    $this->actingAs($member)
        ->post(route('brain.clients.products.store', $client), [
            'name' => 'Nope',
            'slug' => 'nope',
        ])
        ->assertForbidden();

    expect(Product::query()->where('slug', 'nope')->exists())->toBeFalse();
});

it('rejects duplicate product slugs within the same client', function (): void {
    ['org' => $org, 'user' => $user] = clientProductsOwnerContext();

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
    ]);
    Product::factory()->create([
        'company_id' => $client->id,
        'slug' => 'taken',
    ]);

    $this->actingAs($user)
        ->post(route('brain.clients.products.store', $client), [
            'name' => 'Taken Again',
            'slug' => 'taken',
        ])
        ->assertSessionHasErrors('slug');
});
