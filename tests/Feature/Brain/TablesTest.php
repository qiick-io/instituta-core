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
use App\Models\StructuredRow;
use App\Models\StructuredTable;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function tablesSeed(): array
{
    $user = User::factory()->create();
    $org = Organisation::factory()->create(['name' => 'Tables Org']);
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

    $item = Item::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
        'source_id' => $source->id,
        'area' => 'design-system',
        'title' => 'Colour tokens',
        'path' => 'design-system/colours.md',
        'stable_key' => 'design-system/colours',
        'visibility' => VisibilityEnum::Internal,
    ]);

    $table = StructuredTable::factory()->create([
        'item_id' => $item->id,
        'organisation_id' => $org->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
        'table_key' => 'colours',
        'columns' => ['name', 'value'],
        'position' => 0,
    ]);

    StructuredRow::factory()->create([
        'structured_table_id' => $table->id,
        'item_id' => $item->id,
        'organisation_id' => $org->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
        'values' => ['name' => 'primary', 'value' => '#0d9488'],
        'position' => 0,
    ]);

    return compact('user', 'item', 'table');
}

it('lists structured tables in the perimeter', function (): void {
    $seed = tablesSeed();

    $this->actingAs($seed['user'])
        ->get(route('brain.tables.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/tables/index')
            ->where('hasPerimeter', true)
            ->has('tables', 1)
            ->where('tables.0.table_key', 'colours')
            ->where('tables.0.row_count', 1));
});

it('shows one structured table', function (): void {
    $seed = tablesSeed();

    $this->actingAs($seed['user'])
        ->get(route('brain.tables.show', [
            'item' => $seed['item']->id,
            'table' => 'colours',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/tables/show')
            ->where('table.table_key', 'colours')
            ->where('table.rows.0.name', 'primary')
            ->where('table.rows.0.value', '#0d9488'));
});

it('requires authentication for structured tables', function (): void {
    $this->get(route('brain.tables.index'))->assertRedirect();
});
