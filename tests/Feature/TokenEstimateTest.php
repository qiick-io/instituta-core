<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Brain\Support\TokenEstimator;
use App\Enums\SourceProviderEnum;
use App\Mcp\Servers\LocalBrainServer;
use App\Mcp\Tools\InstitutaOutlineTool;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\File;

/**
 * Every read says what it costs in tokens (#230), stored at sync and consistent across reads.
 */
beforeEach(function (): void {
    $this->brainPath = storage_path('framework/testing/token-estimate-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $this->brainPath);
    File::put($this->brainPath.'/rules/brand.md', "---\ntitle: Brand\narea: rules\n---\n\n# Brand\n\nThe brand of the fixture company.\n\n## Colours\n\nPrimary: teal, used for actions and links.\n\n## Typography\n\nInter.\n");

    $organisation = Organisation::factory()->create();
    $this->scope = $organisation->id;
    $source = app(SourceRegistryService::class)->add($organisation, 'Brand fixture', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($source->fresh());

    $principal = Principal::factory()->create(['organisation_id' => $organisation->id]);
    Grant::factory()->create(['principal_id' => $principal->id, 'scope_type' => ScopeTypeEnum::Organisation, 'scope_id' => $organisation->id, 'role' => RoleEnum::Owner, 'max_visibility' => VisibilityEnum::Restricted]);
    $this->headers = ['Authorization' => 'Bearer '.app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: 'reader',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    )['plain']];
});

afterEach(function (): void {
    File::deleteDirectory($this->brainPath);
});

it('estimates characters divided by four, rounded up', function (): void {
    expect(TokenEstimator::forText(null))->toBe(0)
        ->and(TokenEstimator::forText(''))->toBe(0)
        ->and(TokenEstimator::forText('abcd'))->toBe(1)
        ->and(TokenEstimator::forText('abcde'))->toBe(2)
        ->and(TokenEstimator::forText('àèìòù'))->toBe(2);
});

it('gives the same estimates on the outline, each section, the item and its search hits', function (): void {
    $item = Item::query()->where('stable_key', 'rules/brand')->sole();
    $colours = "## Colours\n\nPrimary: teal, used for actions and links.";

    $outline = $this->getJson('/api/v1/items/'.$item->id.'/outline', $this->headers)->assertOk();
    $sectionEstimates = array_column($outline->json('sections'), 'token_estimate', 'anchor');

    expect($sectionEstimates['brand/colours'])->toBe(TokenEstimator::forText($colours))
        ->and($outline->json('token_estimate'))->toBe(array_sum($sectionEstimates))
        ->and($item->token_estimate)->toBe(array_sum($sectionEstimates));

    $this->postJson('/api/v1/sections', ['sections' => [['item' => $item->id, 'anchor' => 'brand/colours']]], $this->headers)
        ->assertJsonPath('sections.0.section.token_estimate', $sectionEstimates['brand/colours']);

    $this->getJson('/api/v1/items/'.$item->id, $this->headers)
        ->assertJsonPath('token_estimate', $item->token_estimate)
        ->assertJsonPath('sections.1.token_estimate', $sectionEstimates['brand/colours']);

    $this->getJson('/api/v1/items?scope='.$this->scope, $this->headers)
        ->assertJsonFragment(['stable_key' => 'rules/brand', 'token_estimate' => $item->token_estimate]);

    $this->getJson('/api/v1/search?scope='.$this->scope.'&q=teal', $this->headers)
        ->assertJsonPath('results.0.anchor', 'brand/colours')
        ->assertJsonPath('results.0.token_estimate', $sectionEstimates['brand/colours']);
});

it('adds estimates to the local reader too', function (): void {
    putenv('INSTITUTA_BRAIN_PATH='.$this->brainPath);

    LocalBrainServer::tool(InstitutaOutlineTool::class, ['id' => 'rules/brand'])
        ->assertOk()
        ->assertSee('"token_estimate":'.Item::query()->where('stable_key', 'rules/brand')->sole()->token_estimate);

    putenv('INSTITUTA_BRAIN_PATH');
});
