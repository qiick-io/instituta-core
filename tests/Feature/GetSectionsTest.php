<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceProviderEnum;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Servers\LocalBrainServer;
use App\Mcp\Tools\Http\InstitutaGetSectionsTool;
use App\Mcp\Tools\InstitutaGetSectionsTool as LocalGetSectionsTool;
use App\Models\ContractCall;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Services\PerimeterResolverService;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\File;

/**
 * Several sections in one call (#225), with a per-entry not_found instead of a failed call.
 */
beforeEach(function (): void {
    config(['instituta.mcp.enabled' => true]);

    $this->brainPath = storage_path('framework/testing/get-sections-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $this->brainPath);
    File::put($this->brainPath.'/rules/brand.md', "---\ntitle: Brand\narea: rules\n---\n\n# Brand\n\nThe brand.\n\n## Colours\n\nPrimary: teal.\n\n## Typography\n\nInter.\n");

    $otherOrganisation = Organisation::factory()->create();
    $otherSource = app(SourceRegistryService::class)->add($otherOrganisation, 'Other', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($otherSource->fresh());
    $this->otherTenantItemId = Item::query()->where('stable_key', 'rules/brand')->sole()->id;

    $organisation = Organisation::factory()->create();
    $source = app(SourceRegistryService::class)->add($organisation, 'Brand fixture', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($source->fresh());

    $principal = Principal::factory()->create(['organisation_id' => $organisation->id]);
    Grant::factory()->create(['principal_id' => $principal->id, 'scope_type' => ScopeTypeEnum::Organisation, 'scope_id' => $organisation->id, 'role' => RoleEnum::Owner, 'max_visibility' => VisibilityEnum::Restricted]);
    $created = app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: 'reader',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );
    $this->plain = $created['plain'];
    $this->key = $created['key'];
});

afterEach(function (): void {
    File::deleteDirectory($this->brainPath);
});

it('reads several sections in one call and reports each missing one on its own entry', function (): void {
    $this->postJson('/api/v1/sections', ['sections' => [
        ['item' => 'rules/brand', 'anchor' => 'brand/colours'],
        ['item' => 'rules/brand.md', 'anchor' => 'brand/typography'],
        ['item' => 'rules/brand', 'anchor' => 'brand/nope'],
        ['item' => $this->otherTenantItemId, 'anchor' => 'brand/colours'],
    ]], ['Authorization' => 'Bearer '.$this->plain])
        ->assertOk()
        ->assertJsonCount(4, 'sections')
        ->assertJsonPath('sections.0.section.markdown', "## Colours\n\nPrimary: teal.")
        ->assertJsonPath('sections.0.section.section_hash', hash('sha256', "## Colours\n\nPrimary: teal."))
        ->assertJsonPath('sections.1.section.heading', 'Typography')
        ->assertJsonPath('sections.2.error', 'not_found')
        ->assertJsonPath('sections.3.item', $this->otherTenantItemId)
        ->assertJsonPath('sections.3.error', 'not_found')
        ->assertJsonMissingPath('sections.3.section');

    expect(ContractCall::query()->where('tool_or_endpoint', 'instituta_get_sections')->count())->toBe(1);
});

it('refuses an empty or oversized batch', function (array $sections): void {
    $this->postJson('/api/v1/sections', ['sections' => $sections], ['Authorization' => 'Bearer '.$this->plain])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'validation_failed');
})->with([
    'empty' => [[]],
    'over 20' => [array_fill(0, 21, ['item' => 'rules/brand', 'anchor' => 'brand/colours'])],
    'missing anchor' => [[['item' => 'rules/brand']]],
]);

it('reads several sections over HTTP MCP and the local stdio server', function (): void {
    request()->attributes->set('instituta.api_key', $this->key->fresh('principal'));
    request()->attributes->set('instituta.perimeter', app(PerimeterResolverService::class)->fromApiKey($this->key->fresh('principal')));

    HttpBrainServer::tool(InstitutaGetSectionsTool::class, ['sections' => [
        ['item' => 'rules/brand', 'anchor' => 'brand/colours'],
        ['item' => $this->otherTenantItemId, 'anchor' => 'brand/colours'],
    ]])->assertOk()->assertSee(['Primary: teal.', 'not_found']);

    HttpBrainServer::tool(InstitutaGetSectionsTool::class, ['sections' => []])
        ->assertHasErrors(['[validation_failed]']);

    putenv('INSTITUTA_BRAIN_PATH='.base_path('tests/Unit/Brain/Fixtures/native'));

    LocalBrainServer::tool(LocalGetSectionsTool::class, ['sections' => [
        ['item' => 'rules/coding', 'anchor' => 'coding-rules'],
        ['item' => 'rules/coding', 'anchor' => 'nope'],
    ]])->assertOk()->assertSee(['Use strict types', 'not_found']);

    putenv('INSTITUTA_BRAIN_PATH');
});

it('refuses malformed section lists over MCP', function (mixed $sections): void {
    putenv('INSTITUTA_BRAIN_PATH='.base_path('tests/Unit/Brain/Fixtures/native'));

    LocalBrainServer::tool(LocalGetSectionsTool::class, ['sections' => $sections])
        ->assertHasErrors(['[validation_failed]']);

    putenv('INSTITUTA_BRAIN_PATH');
})->with([
    'not a list' => [['first' => ['item' => 'rules/coding', 'anchor' => 'coding-rules']]],
    'entry not an object' => [['rules/coding']],
    'empty anchor' => [[['item' => 'rules/coding', 'anchor' => '']]],
    'over 20' => [array_fill(0, 21, ['item' => 'rules/coding', 'anchor' => 'coding-rules'])],
]);
