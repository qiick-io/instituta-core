<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Tools\Http\InstitutaGetSectionTool;
use App\Mcp\Tools\Http\InstitutaGetTool;
use App\Mcp\Tools\Http\InstitutaListTool;
use App\Mcp\Tools\Http\InstitutaScopesTool;
use App\Mcp\Tools\Http\InstitutaSearchTool;
use App\Models\Company;
use App\Models\ContextFreeze;
use App\Models\ContractCall;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Models\VaultVersion;
use App\Services\ApiKeyIssuerService;
use App\Services\PerimeterResolverService;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Enums\ProtocolVersion;

function mcpHeaders(): array
{
    return [
        'Authorization' => 'Bearer unused',
        'Accept' => 'application/json, text/event-stream',
        'Content-Type' => 'application/json',
        'MCP-Protocol-Version' => ProtocolVersion::initializeSupported()[0] ?? '2025-11-25',
    ];
}

function seedOwnerKey(): array
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
        name: 'mcp',
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

it('returns 404 when MCP is disabled', function (): void {
    config(['instituta.mcp.enabled' => false]);
    $seed = seedOwnerKey();

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => ProtocolVersion::initializeSupported()[0] ?? '2025-11-25',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '0'],
        ],
    ], [
        ...mcpHeaders(),
        'Authorization' => 'Bearer '.$seed['plain'],
    ])->assertNotFound();
});

it('returns 401 without a bearer key when MCP is enabled', function (): void {
    config(['instituta.mcp.enabled' => true]);

    $headers = mcpHeaders();
    unset($headers['Authorization']);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => ProtocolVersion::initializeSupported()[0] ?? '2025-11-25',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '0'],
        ],
    ], $headers)->assertUnauthorized();
});

it('lists only items inside the key perimeter and audits the call', function (): void {
    config(['instituta.mcp.enabled' => true]);
    $seed = seedOwnerKey();

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

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['key']->fresh('principal'));
    request()->attributes->set('instituta.api_key', $seed['key']);
    request()->attributes->set('instituta.perimeter', $perimeter);

    HttpBrainServer::tool(InstitutaListTool::class)
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($itemA, $itemB): void {
            $ids = collect($json->toArray()['items'] ?? [])->pluck('id')->all();
            expect($ids)->toContain($itemA->id)->and($ids)->not->toContain($itemB->id);
            $json->etc();
        });

    expect(ContractCall::query()->where('tool_or_endpoint', 'instituta_list')->count())->toBe(1);
});

it('returns null for a cross-tenant get instead of leaking existence', function (): void {
    config(['instituta.mcp.enabled' => true]);
    $seed = seedOwnerKey();

    $orgB = Organisation::factory()->create();
    $sourceB = Source::factory()->create(['organisation_id' => $orgB->id]);
    $foreign = Item::factory()->create([
        'organisation_id' => $orgB->id,
        'source_id' => $sourceB->id,
        'title' => 'Foreign',
        'path' => 'notes/foreign.md',
        'stable_key' => 'notes/foreign',
    ]);

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['key']->fresh('principal'));
    request()->attributes->set('instituta.api_key', $seed['key']);
    request()->attributes->set('instituta.perimeter', $perimeter);

    HttpBrainServer::tool(InstitutaGetTool::class, ['id' => $foreign->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('item', null)->etc());
});

it('honours an active freeze by serving only pinned commits', function (): void {
    config(['instituta.mcp.enabled' => true]);
    $seed = seedOwnerKey();

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

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['key']->fresh('principal'));
    request()->attributes->set('instituta.api_key', $seed['key']);
    request()->attributes->set('instituta.perimeter', $perimeter);

    HttpBrainServer::tool(InstitutaListTool::class)
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($pinned, $vault): void {
            $payload = $json->toArray();
            expect(collect($payload['items'])->pluck('id')->all())->toBe([$pinned->id])
                ->and($payload['frozen'])->toBeTrue()
                ->and($payload['vault_version_id'])->toBe($vault->id);
            $json->etc();
        });
});

it('refuses search until an FTS driver exists', function (): void {
    config(['instituta.mcp.enabled' => true]);
    $seed = seedOwnerKey();
    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['key']->fresh('principal'));
    request()->attributes->set('instituta.api_key', $seed['key']);
    request()->attributes->set('instituta.perimeter', $perimeter);

    HttpBrainServer::tool(InstitutaSearchTool::class, ['q' => 'brand'])
        ->assertHasErrors(['search driver not supported']);
});

it('exposes scopes for the authenticated key', function (): void {
    config(['instituta.mcp.enabled' => true]);
    $seed = seedOwnerKey();
    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['key']->fresh('principal'));
    request()->attributes->set('instituta.api_key', $seed['key']);
    request()->attributes->set('instituta.perimeter', $perimeter);

    HttpBrainServer::tool(InstitutaScopesTool::class)
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($seed): void {
            $json->where('perimeter.scope_id', $seed['org']->id)
                ->where('principal.id', $seed['principal']->id)
                ->etc();
        });
});

it('returns a section when present', function (): void {
    config(['instituta.mcp.enabled' => true]);
    $seed = seedOwnerKey();
    $item = Item::factory()->create([
        'organisation_id' => $seed['org']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'source_id' => $seed['source']->id,
        'path' => 'rules/sec.md',
        'stable_key' => 'rules/sec',
    ]);
    ItemSection::factory()->create([
        'item_id' => $item->id,
        'organisation_id' => $seed['org']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'anchor' => 'overview',
        'heading' => 'Overview',
        'level' => 2,
        'position' => 0,
        'body' => 'Hello',
        'blocks' => [['type' => 'paragraph', 'text' => 'Hello']],
    ]);

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['key']->fresh('principal'));
    request()->attributes->set('instituta.api_key', $seed['key']);
    request()->attributes->set('instituta.perimeter', $perimeter);

    HttpBrainServer::tool(
        InstitutaGetSectionTool::class,
        ['id' => $item->id, 'anchor' => 'overview'],
    )
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('section.anchor', 'overview')
            ->where('section.body', 'Hello')
            ->etc());
});
