<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function resolvedSeed(): array
{
    $user = User::factory()->create();
    $org = Organisation::factory()->create(['name' => 'Resolved Org']);
    $company = Company::factory()->create(['organisation_id' => $org->id]);
    $product = Product::factory()->create([
        'company_id' => $company->id,
        'inherits_from_parent' => true,
        'name' => 'Resolved Product',
    ]);
    $source = Source::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $company->id,
        'product_id' => null,
    ]);
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

    Item::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => null,
        'product_id' => null,
        'source_id' => $source->id,
        'area' => 'style',
        'title' => 'Org voice',
        'path' => 'style/voice.md',
        'stable_key' => 'style/voice',
        'resolution_key' => 'brand.voice',
        'visibility' => VisibilityEnum::Internal,
    ]);

    Item::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
        'source_id' => $source->id,
        'area' => 'style',
        'title' => 'Product voice',
        'path' => 'style/voice-product.md',
        'stable_key' => 'style/voice-product',
        'resolution_key' => 'brand.voice',
        'visibility' => VisibilityEnum::Internal,
    ]);

    return compact('user', 'product');
}

it('renders the resolved view with product override winning', function (): void {
    $seed = resolvedSeed();

    $this->actingAs($seed['user'])
        ->get(route('brain.resolved', ['product' => $seed['product']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/resolved')
            ->where('hasPerimeter', true)
            ->where('product_id', $seed['product']->id)
            ->has('entries', 1)
            ->where('entries.0.resolution_key', 'brand.voice')
            ->where('entries.0.title', 'Product voice')
            ->where('entries.0.overridden', true)
            ->where('token_estimate', 40)
            ->has('agent_view', 1));
});

it('requires authentication for the resolved view', function (): void {
    $this->get(route('brain.resolved'))->assertRedirect();
});
