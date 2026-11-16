<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Mcp\Prompts\InstitutaProductContextPrompt;
use App\Mcp\Prompts\InstitutaProposeChangePrompt;
use App\Mcp\Resources\InstitutaBrainMapResource;
use App\Mcp\Resources\InstitutaResolvedProductResource;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Servers\LocalBrainServer;
use App\Mcp\Tools\Http\InstitutaSimilarTool;
use App\Mcp\Tools\InstitutaGetSectionTool;
use App\Mcp\Tools\InstitutaTableTool;
use App\Models\Company;
use App\Models\ContractCall;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Services\BrainEmbeddingService;
use App\Services\PerimeterResolverService;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Ai\Embeddings;

/**
 * MCP beyond the basic tools (#202): semantic search, prompts, resources, local parity.
 */
beforeEach(function (): void {
    config(['instituta.mcp.enabled' => true]);

    $this->organisation = Organisation::factory()->create();
    $this->company = Company::factory()->create(['organisation_id' => $this->organisation->id]);
    $this->product = Product::factory()->create(['company_id' => $this->company->id]);
    $this->source = Source::factory()->create(['organisation_id' => $this->organisation->id]);

    $principal = Principal::factory()->create(['organisation_id' => $this->organisation->id]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $this->organisation->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);
    $key = app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: 'mcp',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $this->organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    )['key']->fresh('principal');

    request()->attributes->set('instituta.api_key', $key);
    request()->attributes->set('instituta.perimeter', app(PerimeterResolverService::class)->fromApiKey($key));

    $this->item = Item::factory()->create([
        'organisation_id' => $this->organisation->id,
        'source_id' => $this->source->id,
        'stable_key' => 'rules/brand',
        'path' => 'rules/brand.md',
        'title' => 'Brand',
        'area' => 'rules',
        'visibility' => VisibilityEnum::Internal,
    ]);
    $this->section = ItemSection::factory()->create([
        'item_id' => $this->item->id,
        'anchor' => 'brand/colours',
        'heading' => 'Colours',
        'body' => 'Primary teal.',
        'markdown' => "## Colours\n\nPrimary teal.",
    ]);
});

it('refuses semantic search while embeddings are off', function (): void {
    config(['ai.embeddings.enabled' => false]);

    HttpBrainServer::tool(InstitutaSimilarTool::class, ['q' => 'brand colour'])
        ->assertHasErrors(['Embeddings are disabled on this install (AI_EMBEDDINGS_ENABLED=false); use instituta_search.']);
});

it('finds sections by meaning inside the perimeter and audits the call', function (): void {
    config(['ai.embeddings.enabled' => true, 'ai.embeddings.dimensions' => 2]);
    Embeddings::fake([[[1.0, 0.0]], [[1.0, 0.0]]]);
    app(BrainEmbeddingService::class)->embedSection($this->section);

    HttpBrainServer::tool(InstitutaSimilarTool::class, ['q' => 'which colour is primary', 'limit' => 3])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json): void {
            $json->where('query', 'which colour is primary')
                ->where('results.0.anchor', 'brand/colours')
                ->etc();
        });

    expect(ContractCall::query()->where('tool_or_endpoint', 'instituta_similar')->count())->toBe(1);
});

it('lists the prompts and resources on the HTTP server', function (): void {
    HttpBrainServer::prompts()->assertRegistered([InstitutaProductContextPrompt::class, InstitutaProposeChangePrompt::class]);
    // The resolved-product resource has a URI template: MCP lists it under resources/templates/list.
    HttpBrainServer::resources()->assertRegistered(InstitutaBrainMapResource::class);
});

it('loads the resolved context of a product as a prompt', function (): void {
    HttpBrainServer::prompt(InstitutaProductContextPrompt::class, ['product_id' => $this->product->id])
        ->assertOk()
        ->assertSee(["You are working on product {$this->product->id}", 'instituta_get_section', 'Resolved view:']);
});

it('prepares a change with the section markdown and version hashes', function (): void {
    HttpBrainServer::prompt(InstitutaProposeChangePrompt::class, ['item' => 'rules/brand', 'goal' => 'Switch to blue'])
        ->assertOk()
        ->assertSee(['Goal: Switch to blue', 'brand/colours', ItemSection::hashOf($this->section->markdown), $this->item->content_hash]);

    HttpBrainServer::prompt(InstitutaProposeChangePrompt::class, ['item' => 'rules/missing'])
        ->assertHasErrors(['Item not found in your perimeter.']);
});

it('serves the brain map and a product resolved view as resources', function (): void {
    HttpBrainServer::resource(InstitutaBrainMapResource::class)
        ->assertOk()
        ->assertSee(['"area":"rules"', '"count":1']);

    HttpBrainServer::resource(InstitutaResolvedProductResource::class, ['product_id' => $this->product->id])
        ->assertOk();
});

it('reads one section and one table from the local stdio server', function (): void {
    putenv('INSTITUTA_BRAIN_PATH='.base_path('tests/Unit/Brain/Fixtures/native'));

    LocalBrainServer::tool(InstitutaGetSectionTool::class, ['id' => 'rules/coding', 'anchor' => 'coding-rules'])
        ->assertOk()
        ->assertSee(['coding-rules', 'markdown', 'Use strict types']);

    LocalBrainServer::tool(InstitutaGetSectionTool::class, ['id' => 'rules/coding', 'anchor' => 'nope'])
        ->assertHasErrors(['Section not found.']);

    LocalBrainServer::tool(InstitutaTableTool::class, ['id' => 'rules/coding', 'table_key' => 'nope'])
        ->assertHasErrors(['Table not found.']);

    putenv('INSTITUTA_BRAIN_PATH');
});
