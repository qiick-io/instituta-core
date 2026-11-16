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

it('lays the brain out as repository, folders and files, folders first', function (): void {
    $seed = treeSeed();
    $sourceName = Source::query()->sole()->name;

    $this->actingAs($seed['user'])
        ->get(route('explorer.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/explorer')
            ->where('hasPerimeter', true)
            ->where('total', 2)
            ->where('item', null)
            ->has('nodes', 1)
            ->where('nodes.0.kind', 'folder')
            ->where('nodes.0.name', $sourceName)
            ->where('nodes.0.children.0.name', 'decisions')
            ->where('nodes.0.children.1.name', 'rules')
            ->where('nodes.0.children.1.children.0.kind', 'file')
            ->where('nodes.0.children.1.children.0.name', 'Coding rules')
            ->where('nodes.0.children.1.children.0.stable_key', 'rules/coding')
            ->where('nodes.0.children.1.children.0.status', 'active'));
});

it('opens a file next to the tree', function (): void {
    $seed = treeSeed();

    $this->actingAs($seed['user'])
        ->get(route('explorer.show', 'rules/coding'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/explorer')
            ->where('item.title', 'Coding rules')
            ->where('total', 2));
});

it('sends the old map to the explorer', function (): void {
    $this->actingAs(treeSeed()['user'])->get('/map')->assertRedirect('/explorer');
});

it('requires authentication for the tree', function (): void {
    $this->get(route('explorer.index'))->assertRedirect();
});
