<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Ai\Agents\AppAssistant;
use App\Ai\Tools\BrainContextTool;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceProviderEnum;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Tools\Http\InstitutaContextTool;
use App\Models\Company;
use App\Models\ContractCall;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Services\PerimeterResolverService;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Tools\Request;

/**
 * One call returns the context for a task within a token budget (#224).
 */
beforeEach(function (): void {
    config(['instituta.mcp.enabled' => true]);

    $this->brainPath = storage_path('framework/testing/context-pack-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $this->brainPath);
    File::put($this->brainPath.'/rules/handbook.md', "---\ntitle: Handbook\narea: rules\nresolution_key: rules.handbook\nsummary: ".str_repeat('Long handbook summary. ', 120)."\n---\n\n# Handbook\n\nSee the summary.\n");
    File::put($this->brainPath.'/rules/old-brand.md', "---\ntitle: Old brand\narea: rules\nstatus: deprecated\n---\n\n# Old brand\n\n## Old colours\n\nPrimary teal was darker.\n");
    File::put($this->brainPath.'/rules/brand.md', "---\ntitle: Brand\narea: rules\n---\n\n# Brand\n\nThe brand.\n\n## Colours\n\nPrimary: teal, used for actions.\n\n## Teal history\n\n".str_repeat('The teal shade evolved over many releases. ', 80)."\n");

    $otherOrganisation = Organisation::factory()->create();
    $otherSource = app(SourceRegistryService::class)->add($otherOrganisation, 'Other', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($otherSource->fresh());
    $this->otherTenantItemIds = Item::query()->pluck('id')->all();

    $this->organisation = Organisation::factory()->create();
    $source = app(SourceRegistryService::class)->add($this->organisation, 'Brand fixture', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($source->fresh());
    $this->product = Product::factory()->create(['company_id' => Company::factory()->create(['organisation_id' => $this->organisation->id])->id]);

    $principal = Principal::factory()->create(['organisation_id' => $this->organisation->id]);
    Grant::factory()->create(['principal_id' => $principal->id, 'scope_type' => ScopeTypeEnum::Organisation, 'scope_id' => $this->organisation->id, 'role' => RoleEnum::Owner, 'max_visibility' => VisibilityEnum::Restricted]);
    $created = app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: 'reader',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $this->organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );
    $this->headers = ['Authorization' => 'Bearer '.$created['plain']];
    $this->key = $created['key'];
});

afterEach(function (): void {
    File::deleteDirectory($this->brainPath);
});

function contextPack(array $query): array
{
    return test()->getJson('/api/v1/context?'.http_build_query(['scope' => test()->organisation->id, ...$query]), test()->headers)
        ->assertOk()
        ->json();
}

it('returns the relevant sections already read, active entries first, within the budget', function (): void {
    $pack = contextPack(['q' => 'teal', 'budget' => 500]);

    expect($pack['token_estimate'])->toBeLessThanOrEqual(500)
        ->and($pack['token_estimate'])->toBe(array_sum(array_column($pack['sections'], 'token_estimate')))
        ->and($pack['sections'][0]['anchor'])->toBe('brand/colours')
        ->and($pack['sections'][0]['markdown'])->toBe("## Colours\n\nPrimary: teal, used for actions.")
        ->and($pack['sections'][0]['citation'])->toMatchArray(['path' => 'rules/brand.md', 'section' => 'brand/colours'])
        ->and(array_values(array_unique(array_column($pack['sections'], 'status'))))->toBe(['active', 'deprecated'])
        ->and($pack['omitted'])->toHaveCount(1)
        ->and($pack['omitted'][0])->toMatchArray(['kind' => 'section', 'anchor' => 'brand/teal-history']);

    $includedItemIds = array_column($pack['sections'], 'item');
    expect(array_intersect($includedItemIds, $this->otherTenantItemIds))->toBe([])
        ->and(ContractCall::query()->where('tool_or_endpoint', 'instituta_context')->sole()->token_estimate)->toBe($pack['token_estimate']);
});

it('puts a product\'s resolved entries first and fits the larger sections in a larger budget', function (): void {
    $pack = contextPack(['q' => 'teal', 'product_id' => $this->product->id, 'budget' => 4000]);

    expect($pack['resolved'])->not->toBeEmpty()
        ->and(array_column($pack['resolved'], 'resolution_key'))->toContain('rules.coding')
        ->and($pack['resolved'][0])->toHaveKeys(['title', 'summary', 'status', 'citation'])
        ->and($pack['omitted'])->toBe([])
        ->and(array_column($pack['sections'], 'anchor'))->toContain('brand/teal-history');
});

it('validates the budget and the scope', function (): void {
    $this->getJson('/api/v1/context?scope='.$this->organisation->id.'&q=teal&budget=100', $this->headers)
        ->assertUnprocessable()
        ->assertJsonPath('error', 'validation_failed');

    $this->getJson('/api/v1/context?scope=someone-else&q=teal', $this->headers)->assertNotFound();
});

it('serves the context pack over MCP and gives it to the assistant first', function (): void {
    request()->attributes->set('instituta.api_key', $this->key->fresh('principal'));
    request()->attributes->set('instituta.perimeter', app(PerimeterResolverService::class)->fromApiKey($this->key->fresh('principal')));

    HttpBrainServer::tool(InstitutaContextTool::class, ['q' => 'teal'])
        ->assertOk()
        ->assertSee(['brand/colours', 'Primary: teal, used for actions.']);

    HttpBrainServer::tool(InstitutaContextTool::class, ['q' => ' '])->assertHasErrors(['[validation_failed]']);

    $tools = iterator_to_array((new AppAssistant(app(PerimeterResolverService::class)->fromApiKey($this->key->fresh('principal'))))->tools());
    expect($tools[0])->toBeInstanceOf(BrainContextTool::class);
});

it('lists a resolved entry that does not fit the budget as omitted', function (): void {
    $pack = contextPack(['q' => 'teal', 'product_id' => $this->product->id, 'budget' => 500]);

    expect(array_column($pack['resolved'], 'resolution_key'))->not->toContain('rules.handbook')
        ->and(array_column($pack['omitted'], 'resolution_key'))->toContain('rules.handbook');
});

it('answers the assistant tool with the pack as JSON', function (): void {
    $perimeter = app(PerimeterResolverService::class)->fromApiKey($this->key->fresh('principal'));

    $answer = json_decode((new BrainContextTool($perimeter))->handle(new Request(['q' => 'teal', 'product_id' => $this->product->id, 'area' => 'rules'])), true);

    expect(array_column($answer['sections'], 'anchor'))->toContain('brand/colours')
        ->and($answer['budget'])->toBe(3000);
});
