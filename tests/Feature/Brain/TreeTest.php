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

function treeSeed(): array
{
    $user = User::factory()->create();
    $org = Organisation::factory()->create(['name' => 'Tree Org']);
    $company = Company::factory()->create(['organisation_id' => $org->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $source = Source::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
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
        'company_id' => $company->id,
        'product_id' => $product->id,
        'source_id' => $source->id,
        'area' => 'rules',
        'title' => 'Coding rules',
        'path' => 'rules/coding.md',
        'stable_key' => 'rules/coding',
        'type' => 'document',
        'summary' => 'House coding rules',
        'visibility' => VisibilityEnum::Internal,
    ]);

    Item::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
        'source_id' => $source->id,
        'area' => 'decisions',
        'title' => 'ADR 1',
        'path' => 'decisions/adr-1.md',
        'stable_key' => 'decisions/adr-1',
        'type' => 'decision',
        'visibility' => VisibilityEnum::Internal,
    ]);

    return compact('user', 'org');
}

it('renders the brain tree grouped by area', function (): void {
    $seed = treeSeed();

    $this->actingAs($seed['user'])
        ->get(route('explorer.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/tree')
            ->where('hasPerimeter', true)
            ->where('total', 2)
            ->where('area', null)
            ->has('groups', 2)
            ->where('groups.0.area', 'decisions')
            ->where('groups.1.area', 'rules')
            ->where('groups.1.items.0.title', 'Coding rules'));
});

it('filters the tree by area query', function (): void {
    $seed = treeSeed();

    $this->actingAs($seed['user'])
        ->get(route('explorer.index', ['area' => 'rules']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/tree')
            ->where('area', 'rules')
            ->where('total', 1)
            ->has('groups', 1)
            ->where('groups.0.area', 'rules'));
});

it('requires authentication for the tree', function (): void {
    $this->get(route('explorer.index'))->assertRedirect();
});
