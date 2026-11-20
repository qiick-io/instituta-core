<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Brain\Exceptions\DenylistHitException;
use App\Enums\ProposalStatusEnum;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Tools\Http\InstitutaGetTool;
use App\Mcp\Tools\Http\InstitutaGraphTool;
use App\Mcp\Tools\Http\InstitutaListTool;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\Source;
use App\Models\User;
use App\Services\ClientExportService;
use App\Services\DenylistGuardService;
use App\Services\IndexedContractService;
use App\Services\PerimeterResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Cross-tenant suite: one seed, assert no leakage across UI, MCP, REST,
 * search, graph (API + UI), resolved view, export, and proposals.
 */
function isolationSeed(): array
{
    $userA = User::factory()->create(['first_name' => 'Owner', 'last_name' => 'A']);
    $orgA = Organisation::factory()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $companyA = Company::factory()->client()->create([
        'organisation_id' => $orgA->id,
        'slug' => 'client',
        'name' => 'Client A',
    ]);
    $productA = Product::factory()->create([
        'company_id' => $companyA->id,
        'slug' => 'app',
        'name' => 'App A',
        'inherits_from_parent' => true,
    ]);
    $sourceA = Source::factory()->create([
        'organisation_id' => $orgA->id,
        'company_id' => $companyA->id,
        'product_id' => $productA->id,
        'name' => 'source-a',
        'last_synced_commit' => 'aaa111',
    ]);
    $principalA = Principal::factory()->create([
        'organisation_id' => $orgA->id,
        'user_id' => $userA->id,
        'type' => 'person',
        'name' => $userA->name,
    ]);
    Grant::factory()->create([
        'principal_id' => $principalA->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $orgA->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);
    $keyA = app(CreateApiKeyAction::class)->execute(
        principal: $principalA,
        name: 'tenant-a',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $orgA->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );

    $itemA = Item::factory()->create([
        'organisation_id' => $orgA->id,
        'company_id' => $companyA->id,
        'product_id' => $productA->id,
        'source_id' => $sourceA->id,
        'area' => 'rules',
        'title' => 'Tenant A rules',
        'path' => 'rules/a.md',
        'stable_key' => 'rules/a',
        'resolution_key' => 'brand.voice',
        'summary' => 'brand voice for tenant A',
        'visibility' => VisibilityEnum::Internal,
    ]);
    ItemSection::factory()->create([
        'item_id' => $itemA->id,
        'organisation_id' => $orgA->id,
        'company_id' => $companyA->id,
        'product_id' => $productA->id,
        'anchor' => 'body',
        'heading' => 'Body',
        'level' => 2,
        'position' => 0,
        'body' => 'brand voice for tenant A',
        'blocks' => [['type' => 'paragraph', 'text' => 'brand voice for tenant A']],
    ]);

    $userB = User::factory()->create(['first_name' => 'Owner', 'last_name' => 'B']);
    $orgB = Organisation::factory()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $companyB = Company::factory()->client()->create([
        'organisation_id' => $orgB->id,
        'slug' => 'client',
        'name' => 'Client B',
    ]);
    $productB = Product::factory()->create([
        'company_id' => $companyB->id,
        'slug' => 'app',
        'name' => 'App B',
        'inherits_from_parent' => true,
    ]);
    $sourceB = Source::factory()->create([
        'organisation_id' => $orgB->id,
        'company_id' => $companyB->id,
        'product_id' => $productB->id,
        'name' => 'source-b',
        'last_synced_commit' => 'bbb222',
    ]);
    $principalB = Principal::factory()->create([
        'organisation_id' => $orgB->id,
        'user_id' => $userB->id,
        'type' => 'person',
        'name' => $userB->name,
    ]);
    Grant::factory()->create([
        'principal_id' => $principalB->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $orgB->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);
    $keyB = app(CreateApiKeyAction::class)->execute(
        principal: $principalB,
        name: 'tenant-b',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $orgB->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );

    $itemB = Item::factory()->create([
        'organisation_id' => $orgB->id,
        'company_id' => $companyB->id,
        'product_id' => $productB->id,
        'source_id' => $sourceB->id,
        'area' => 'rules',
        'title' => 'Tenant B secret',
        'path' => 'rules/b.md',
        'stable_key' => 'rules/b',
        'resolution_key' => 'brand.voice',
        'summary' => 'brand voice leaked across tenants',
        'visibility' => VisibilityEnum::Internal,
    ]);
    ItemSection::factory()->create([
        'item_id' => $itemB->id,
        'organisation_id' => $orgB->id,
        'company_id' => $companyB->id,
        'product_id' => $productB->id,
        'anchor' => 'body',
        'heading' => 'Body',
        'level' => 2,
        'position' => 0,
        'body' => 'brand voice leaked across tenants',
        'blocks' => [['type' => 'paragraph', 'text' => 'brand voice leaked across tenants']],
    ]);

    return [
        'userA' => $userA,
        'orgA' => $orgA,
        'companyA' => $companyA,
        'productA' => $productA,
        'sourceA' => $sourceA,
        'principalA' => $principalA,
        'itemA' => $itemA,
        'keyA' => $keyA['key'],
        'plainA' => $keyA['plain'],
        'userB' => $userB,
        'orgB' => $orgB,
        'companyB' => $companyB,
        'productB' => $productB,
        'sourceB' => $sourceB,
        'principalB' => $principalB,
        'itemB' => $itemB,
        'keyB' => $keyB['key'],
        'plainB' => $keyB['plain'],
    ];
}

it('keeps brain UI lists and reads inside the session perimeter', function (): void {
    $seed = isolationSeed();

    $this->actingAs($seed['userA'])
        ->get(route('explorer.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/explorer')
            ->where('total', 1)
            ->where('nodes.0.children.0.children.0.name', 'Tenant A rules'));

    $this->actingAs($seed['userA'])
        ->get(route('repositories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('sources', 1)
            ->where('sources.0.name', 'source-a'));

    $this->actingAs($seed['userA'])
        ->get(route('explorer.show', ['item' => $seed['itemA']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('item.title', 'Tenant A rules'));

    $this->actingAs($seed['userA'])
        ->get(route('explorer.show', ['item' => $seed['itemB']->id]))
        ->assertNotFound();

    $this->actingAs($seed['userA'])
        ->get(route('resolved', ['product' => $seed['productA']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.title', 'Tenant A rules'));

    $this->actingAs($seed['userA'])
        ->from(route('dashboard'))
        ->post(route('scope.update'), [
            'scope_type' => 'organisation',
            'scope_id' => $seed['orgB']->id,
        ])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors('scope_id');
});

it('keeps MCP list and get inside the API key perimeter', function (): void {
    config(['instituta.mcp.enabled' => true]);
    $seed = isolationSeed();

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['keyA']->fresh('principal'));
    request()->attributes->set('instituta.api_key', $seed['keyA']);
    request()->attributes->set('instituta.perimeter', $perimeter);

    HttpBrainServer::tool(InstitutaListTool::class)
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($seed): void {
            $ids = collect($json->toArray()['items'] ?? [])->pluck('id')->all();
            expect($ids)->toContain($seed['itemA']->id)
                ->and($ids)->not->toContain($seed['itemB']->id);
            $json->etc();
        });

    HttpBrainServer::tool(InstitutaGetTool::class, ['id' => $seed['itemB']->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('item', null)->etc());
});

it('keeps REST list and get inside the API key perimeter', function (): void {
    $seed = isolationSeed();
    $auth = ['Authorization' => 'Bearer '.$seed['plainA']];

    $response = $this->getJson('/api/v1/items?scope='.$seed['orgA']->id, $auth)->assertOk();
    $ids = collect($response->json('items'))->pluck('id')->all();
    expect($ids)->toContain($seed['itemA']->id)
        ->and($ids)->not->toContain($seed['itemB']->id);

    $this->getJson('/api/v1/items/'.$seed['itemB']->id, $auth)->assertNotFound();
});

it('keeps search hits inside the API key perimeter', function (): void {
    expect(in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true))->toBeTrue();

    $seed = isolationSeed();
    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['keyA']->fresh('principal'));
    $payload = app(IndexedContractService::class)->search(
        $perimeter,
        $seed['keyA'],
        'brand voice',
    );

    $ids = collect($payload['results'])->pluck('id')->all();
    expect($ids)->toContain($seed['itemA']->id)
        ->and($ids)->not->toContain($seed['itemB']->id);
});

it('exports only the calling client subtree and never the other tenant', function (): void {
    $seed = isolationSeed();
    $dest = storage_path('framework/testing/exports/isolation-a');
    File::deleteDirectory($dest);

    $result = app(ClientExportService::class)->export($seed['companyA'], $dest);
    $titles = collect(json_decode(File::get($dest.'/index.json'), true, 512, JSON_THROW_ON_ERROR)['items'])
        ->pluck('title')
        ->all();

    expect($result['manifest']['company']['id'])->toBe($seed['companyA']->id)
        ->and($result['manifest']['counts']['items'])->toBe(1)
        ->and($titles)->toBe(['Tenant A rules'])
        ->and($titles)->not->toContain('Tenant B secret');
});

it('keeps graph neighbours inside the API key perimeter', function (): void {
    config(['instituta.mcp.enabled' => true]);
    $seed = isolationSeed();

    $neighbourA = Item::factory()->create([
        'organisation_id' => $seed['orgA']->id,
        'company_id' => $seed['companyA']->id,
        'product_id' => $seed['productA']->id,
        'source_id' => $seed['sourceA']->id,
        'area' => 'notes',
        'title' => 'Tenant A neighbour',
        'path' => 'notes/a-neighbour.md',
        'stable_key' => 'notes/a-neighbour',
        'visibility' => VisibilityEnum::Internal,
    ]);

    ItemLink::factory()->create([
        'from_item_id' => $seed['itemA']->id,
        'to_item_id' => $neighbourA->id,
        'organisation_id' => $seed['orgA']->id,
        'company_id' => $seed['companyA']->id,
        'product_id' => $seed['productA']->id,
        'target' => $neighbourA->stable_key,
        'relation' => 'links_to',
    ]);
    // Cross-tenant edge must not leak through graph.
    ItemLink::factory()->create([
        'from_item_id' => $seed['itemA']->id,
        'to_item_id' => $seed['itemB']->id,
        'organisation_id' => $seed['orgA']->id,
        'company_id' => $seed['companyA']->id,
        'product_id' => $seed['productA']->id,
        'target' => $seed['itemB']->stable_key,
        'relation' => 'links_to',
    ]);

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['keyA']->fresh('principal'));
    $graph = app(IndexedContractService::class)->graph(
        $perimeter,
        $seed['keyA'],
        $seed['itemA']->id,
        1,
    );
    $ids = collect($graph['nodes'])->pluck('id')->all();
    expect($ids)->toContain($seed['itemA']->id)
        ->and($ids)->toContain($neighbourA->id)
        ->and($ids)->not->toContain($seed['itemB']->id);

    $auth = ['Authorization' => 'Bearer '.$seed['plainA']];
    $rest = $this->getJson('/api/v1/items/'.$seed['itemA']->id.'/neighbours?depth=1', $auth)->assertOk();
    $restIds = collect($rest->json('nodes'))->pluck('id')->all();
    expect($restIds)->toContain($neighbourA->id)
        ->and($restIds)->not->toContain($seed['itemB']->id);

    $this->getJson('/api/v1/items/'.$seed['itemB']->id.'/neighbours?depth=1', $auth)
        ->assertOk()
        ->assertJsonPath('nodes', []);

    request()->attributes->set('instituta.api_key', $seed['keyA']);
    request()->attributes->set('instituta.perimeter', $perimeter);

    HttpBrainServer::tool(InstitutaGraphTool::class, ['id' => $seed['itemA']->id, 'depth' => 1])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($seed, $neighbourA): void {
            $ids = collect($json->toArray()['nodes'] ?? [])->pluck('id')->all();
            expect($ids)->toContain($seed['itemA']->id)
                ->and($ids)->toContain($neighbourA->id)
                ->and($ids)->not->toContain($seed['itemB']->id);
            $json->etc();
        });
});

it('keeps the graph UI inside the session perimeter', function (): void {
    $seed = isolationSeed();

    $this->actingAs($seed['userA'])
        ->get(route('graph'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/graph')
            ->where('nodes', fn ($nodes): bool => collect($nodes)->pluck('id')->contains($seed['itemA']->id)
                && ! collect($nodes)->pluck('id')->contains($seed['itemB']->id)));
});

it('keeps proposals UI inside the organisation perimeter', function (): void {
    $seed = isolationSeed();

    $proposalA = Proposal::factory()->create([
        'organisation_id' => $seed['orgA']->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $seed['orgA']->id,
        'proposed_by_principal_id' => $seed['principalA']->id,
        'title' => 'Tenant A proposal',
        'status' => ProposalStatusEnum::Open,
    ]);
    $proposalB = Proposal::factory()->create([
        'organisation_id' => $seed['orgB']->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $seed['orgB']->id,
        'proposed_by_principal_id' => $seed['principalB']->id,
        'title' => 'Tenant B proposal',
        'status' => ProposalStatusEnum::Open,
    ]);

    $this->actingAs($seed['userA'])
        ->get(route('review.proposals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/proposals')
            ->has('proposals', 1)
            ->where('proposals.0.title', 'Tenant A proposal'));

    $this->actingAs($seed['userA'])
        ->get(route('review.proposals.show', $proposalA->id))
        ->assertOk();

    $this->actingAs($seed['userA'])
        ->get(route('review.proposals.show', $proposalB->id))
        ->assertNotFound();
});

it('refuses denylist hits on name url and path when tokens are configured', function (): void {
    config(['instituta.ingest.denylist' => ['acme', 'globex', 'initech']]);
    $guard = app(DenylistGuardService::class);

    expect(fn () => $guard->assertAllowed(name: 'Acme Docs'))->toThrow(DenylistHitException::class)
        ->and(fn () => $guard->assertAllowed(remoteUrl: 'https://github.com/example/GLOBEX-docs.git'))->toThrow(DenylistHitException::class)
        ->and(fn () => $guard->assertAllowed(localPath: '/tmp/initech-brain'))->toThrow(DenylistHitException::class);

    config(['instituta.ingest.denylist' => []]);

    $guard->assertAllowed(name: 'Acme Globex Initech Docs', remoteUrl: 'https://github.com/acme/globex.git', localPath: '/tmp/initech');
});
