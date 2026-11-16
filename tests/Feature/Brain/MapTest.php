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

function mapSeed(): array
{
    $user = User::factory()->create();
    $org = Organisation::factory()->create(['name' => 'Map Org']);
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
        'visibility' => VisibilityEnum::Internal,
    ]);

    return compact('user', 'org');
}

it('renders the brain map with area coverage and gaps', function (): void {
    $seed = mapSeed();

    $this->actingAs($seed['user'])
        ->get(route('map'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/map')
            ->where('hasPerimeter', true)
            ->has('areas', 8)
            ->where('areas.0.area', 'rules')
            ->where('areas.0.count', 1)
            ->where('areas.0.empty', false)
            ->where('areas.1.area', 'decisions')
            ->where('areas.1.empty', true));
});

it('requires authentication for the map', function (): void {
    $this->get(route('map'))->assertRedirect();
});
