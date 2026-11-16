<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function readingSeed(): array
{
    $user = User::factory()->create();
    $org = Organisation::factory()->create(['name' => 'Reading Org']);
    $company = Company::factory()->create(['organisation_id' => $org->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $source = Source::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
        'name' => 'product-brain',
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

    $item = Item::factory()->create([
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
        'commit' => 'abcdef1234567890',
    ]);

    ItemSection::factory()->create([
        'item_id' => $item->id,
        'organisation_id' => $org->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
        'anchor' => 'style',
        'heading' => 'Style',
        'level' => 2,
        'position' => 1,
        'body' => 'Prefer boring code.',
        'blocks' => [
            ['type' => 'text', 'payload' => ['text' => 'Prefer boring code.']],
            ['type' => 'code', 'payload' => ['language' => 'php', 'source' => 'echo 1;']],
        ],
    ]);

    return compact('user', 'item');
}

it('renders the reading view for an item in the perimeter', function (): void {
    $seed = readingSeed();

    $this->actingAs($seed['user'])
        ->get(route('explorer.show', ['item' => $seed['item']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/reading')
            ->where('item.title', 'Coding rules')
            ->where('item.path', 'rules/coding.md')
            ->where('item.citation.source', 'product-brain')
            ->has('item.sections', 1)
            ->where('item.sections.0.anchor', 'style')
            ->where('item.sections.0.blocks.0.type', 'text'));
});

it('resolves reading view by stable_key', function (): void {
    $seed = readingSeed();

    $this->actingAs($seed['user'])
        ->get(route('explorer.show', ['item' => 'rules/coding']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/reading')
            ->where('item.stable_key', 'rules/coding'));
});

it('returns 404 when the item is outside the perimeter', function (): void {
    $seed = readingSeed();
    $other = Item::factory()->create([
        'area' => 'notes',
        'title' => 'Secret',
        'path' => 'notes/secret.md',
        'stable_key' => 'notes/secret',
    ]);

    $this->actingAs($seed['user'])
        ->get(route('explorer.show', ['item' => $other->id]))
        ->assertNotFound();
});

it('requires authentication for the reading view', function (): void {
    $this->get(route('explorer.show', ['item' => 'x']))->assertRedirect();
});
