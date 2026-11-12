<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Company;
use App\Models\ContextFreeze;
use App\Models\ContractCall;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Models\StructuredRow;
use App\Models\StructuredTable;
use App\Models\VaultVersion;
use App\Services\ApiKeyIssuerService;

function restSeedOwnerKey(): array
{
    $org = Organisation::factory()->create();
    $company = Company::factory()->create(['organisation_id' => $org->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $source = Source::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
    ]);
    $principal = Principal::factory()->create(['organisation_id' => $org->id]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    $created = app(ApiKeyIssuerService::class)->create(
        principal: $principal,
        name: 'rest',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $org->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );

    return [
        'org' => $org,
        'company' => $company,
        'product' => $product,
        'source' => $source,
        'principal' => $principal,
        'key' => $created['key'],
        'plain' => $created['plain'],
    ];
}

function restAuth(array $seed): array
{
    return ['Authorization' => 'Bearer '.$seed['plain']];
}

it('returns 401 without a bearer key on REST', function (): void {
    $this->getJson('/api/v1/scopes')->assertUnauthorized();
});

it('rejects keys without brain:read on REST', function (): void {
    $seed = restSeedOwnerKey();
    $seed['key']->forceFill(['abilities' => []])->save();

    $this->getJson('/api/v1/scopes', restAuth($seed))->assertForbidden();
});

it('lists only items inside the key perimeter and audits the call', function (): void {
    $seed = restSeedOwnerKey();

    $itemA = Item::factory()->create([
        'organisation_id' => $seed['org']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'source_id' => $seed['source']->id,
        'title' => 'Visible',
        'visibility' => VisibilityEnum::Internal,
        'area' => 'rules',
        'path' => 'rules/visible.md',
        'stable_key' => 'rules/visible',
    ]);

    $orgB = Organisation::factory()->create();
    $sourceB = Source::factory()->create(['organisation_id' => $orgB->id]);
    $itemB = Item::factory()->create([
        'organisation_id' => $orgB->id,
        'source_id' => $sourceB->id,
        'title' => 'Secret other tenant',
        'visibility' => VisibilityEnum::Internal,
        'path' => 'rules/other.md',
        'stable_key' => 'rules/other',
    ]);

    $response = $this->getJson(
        '/api/v1/items?scope='.$seed['org']->id,
        restAuth($seed),
    )->assertOk();

    $ids = collect($response->json('items'))->pluck('id')->all();
    expect($ids)->toContain($itemA->id)->and($ids)->not->toContain($itemB->id);
    expect(ContractCall::query()->where('tool_or_endpoint', 'instituta_list')->count())->toBe(1);
});

it('returns 404 for a cross-tenant get instead of leaking existence', function (): void {
    $seed = restSeedOwnerKey();

    $orgB = Organisation::factory()->create();
    $sourceB = Source::factory()->create(['organisation_id' => $orgB->id]);
    $foreign = Item::factory()->create([
        'organisation_id' => $orgB->id,
        'source_id' => $sourceB->id,
        'title' => 'Foreign',
        'path' => 'notes/foreign.md',
        'stable_key' => 'notes/foreign',
    ]);

    $this->getJson('/api/v1/items/'.$foreign->id, restAuth($seed))
        ->assertNotFound();
});

it('honours an active freeze by serving only pinned commits', function (): void {
    $seed = restSeedOwnerKey();

    $pinned = Item::factory()->create([
        'organisation_id' => $seed['org']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'source_id' => $seed['source']->id,
        'commit' => 'aaa111',
        'title' => 'Pinned',
        'path' => 'rules/pinned.md',
        'stable_key' => 'rules/pinned',
    ]);
    Item::factory()->create([
        'organisation_id' => $seed['org']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'source_id' => $seed['source']->id,
        'commit' => 'bbb222',
        'title' => 'Newer',
        'path' => 'rules/newer.md',
        'stable_key' => 'rules/newer',
    ]);

    $vault = VaultVersion::factory()->create([
        'organisation_id' => $seed['org']->id,
        'label' => 'instituta@v1',
        'source_commits' => [$seed['source']->id => 'aaa111'],
    ]);
    ContextFreeze::factory()->create([
        'organisation_id' => $seed['org']->id,
        'company_id' => null,
        'product_id' => null,
        'vault_version_id' => $vault->id,
        'frozen_by_principal_id' => $seed['principal']->id,
        'frozen_at' => now(),
        'lifted_at' => null,
    ]);

    $this->getJson('/api/v1/items?scope='.$seed['org']->id, restAuth($seed))
        ->assertOk()
        ->assertJsonPath('items.0.id', $pinned->id)
        ->assertJsonPath('frozen', true)
        ->assertJsonPath('vault_version_id', $vault->id);
});

it('returns 501 for search until an FTS driver exists', function (): void {
    $seed = restSeedOwnerKey();

    $this->getJson(
        '/api/v1/search?scope='.$seed['org']->id.'&q=brand',
        restAuth($seed),
    )->assertStatus(501)
        ->assertJsonPath('message', 'search driver not supported');
});

it('exposes scopes for the authenticated key', function (): void {
    $seed = restSeedOwnerKey();

    $this->getJson('/api/v1/scopes', restAuth($seed))
        ->assertOk()
        ->assertJsonPath('perimeter.scope_id', $seed['org']->id)
        ->assertJsonPath('principal.id', $seed['principal']->id);
});

it('returns outline get table resolve and neighbours for in-perimeter items', function (): void {
    $seed = restSeedOwnerKey();

    $item = Item::factory()->create([
        'organisation_id' => $seed['org']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'source_id' => $seed['source']->id,
        'path' => 'rules/full.md',
        'stable_key' => 'rules/full',
        'title' => 'Full item',
        'resolution_key' => 'theme.primary',
        'visibility' => VisibilityEnum::Internal,
    ]);
    ItemSection::factory()->create([
        'item_id' => $item->id,
        'organisation_id' => $seed['org']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'anchor' => 'body',
        'heading' => 'Body',
        'level' => 2,
        'position' => 0,
        'body' => 'Section body',
        'blocks' => [['type' => 'paragraph', 'text' => 'Section body']],
    ]);
    $table = StructuredTable::factory()->create([
        'item_id' => $item->id,
        'organisation_id' => $seed['org']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'table_key' => 'tokens',
        'columns' => ['name', 'value'],
        'position' => 0,
    ]);
    StructuredRow::factory()->create([
        'structured_table_id' => $table->id,
        'item_id' => $item->id,
        'organisation_id' => $seed['org']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'values' => ['name' => 'primary', 'value' => '#0F766E'],
        'position' => 0,
    ]);

    $neighbour = Item::factory()->create([
        'organisation_id' => $seed['org']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'source_id' => $seed['source']->id,
        'path' => 'rules/neighbour.md',
        'stable_key' => 'rules/neighbour',
        'title' => 'Neighbour',
    ]);
    ItemLink::factory()->create([
        'from_item_id' => $item->id,
        'to_item_id' => $neighbour->id,
        'organisation_id' => $seed['org']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'target' => $neighbour->stable_key,
        'relation' => 'links_to',
    ]);

    $this->getJson('/api/v1/items/'.$item->id, restAuth($seed))
        ->assertOk()
        ->assertJsonPath('id', $item->id)
        ->assertJsonStructure(['sections', 'tables']);

    $this->getJson('/api/v1/items/'.$item->id.'/outline', restAuth($seed))
        ->assertOk()
        ->assertJsonPath('stable_key', 'rules/full')
        ->assertJsonStructure(['sections']);

    $this->getJson(
        '/api/v1/items/'.$item->id.'/tables/tokens?filter='.urlencode(json_encode(['name' => 'primary'])),
        restAuth($seed),
    )->assertOk()
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.value', '#0F766E');

    $this->getJson('/api/v1/items/'.$item->id.'/sections/body', restAuth($seed))
        ->assertOk()
        ->assertJsonPath('anchor', 'body')
        ->assertJsonPath('body', 'Section body');

    $resolve = $this->getJson('/api/v1/products/'.$seed['product']->id.'/resolved', restAuth($seed))
        ->assertOk();
    expect(collect($resolve->json('entries'))->pluck('resolution_key')->all())
        ->toContain('theme.primary');

    $graph = $this->getJson('/api/v1/items/'.$item->id.'/neighbours?depth=1', restAuth($seed))
        ->assertOk();
    $ids = collect($graph->json('nodes'))->pluck('id')->all();
    expect($ids)->toContain($item->id)->and($ids)->toContain($neighbour->id);
});

it('requires scope on list and search', function (): void {
    $seed = restSeedOwnerKey();

    $this->getJson('/api/v1/items', restAuth($seed))->assertUnprocessable();
    $this->getJson('/api/v1/search?q=x', restAuth($seed))->assertUnprocessable();
});
