<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceProviderEnum;
use App\Models\Grant;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Principal;
use App\Services\BrainEmbeddingService;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Embeddings;

/**
 * Search hits point at the matching section (#216), so an agent opens it directly.
 */
beforeEach(function (): void {
    $this->brainPath = storage_path('framework/testing/section-search-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $this->brainPath);
    File::put($this->brainPath.'/rules/brand.md', <<<'MARKDOWN'
        ---
        title: Brand
        area: rules
        ---

        # Brand

        The brand of the fixture company.

        ## Colours

        Primary: teal, used for actions.

        ## Typography

        Inter for the interface.

        ## Voice

        Calm and precise, never teal-coloured prose.
        MARKDOWN);

    $organisation = Organisation::factory()->create();
    $this->scope = $organisation->id;
    $source = app(SourceRegistryService::class)->add($organisation, 'Brand fixture', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($source->fresh());

    $principal = Principal::factory()->create(['organisation_id' => $organisation->id]);
    Grant::factory()->create(['principal_id' => $principal->id, 'scope_type' => ScopeTypeEnum::Organisation, 'scope_id' => $organisation->id, 'role' => RoleEnum::Owner, 'max_visibility' => VisibilityEnum::Restricted]);
    $this->plain = app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: 'search',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    )['plain'];
});

afterEach(function (): void {
    File::deleteDirectory($this->brainPath);
});

it('returns the matching section with its anchor, heading and cited section', function (): void {
    skipUnlessKeywordSearchSupported();
    $this->getJson('/api/v1/search?scope='.$this->scope.'&q=Inter', ['Authorization' => 'Bearer '.$this->plain])
        ->assertOk()
        ->assertJsonPath('results.0.anchor', 'brand/typography')
        ->assertJsonPath('results.0.heading', 'Typography')
        ->assertJsonPath('results.0.citation.section', 'brand/typography')
        ->assertJsonPath('results.0.citation.path', 'rules/brand.md');
});

it('lists at most two sections of the same item', function (): void {
    skipUnlessKeywordSearchSupported();

    $response = $this->getJson('/api/v1/search?scope='.$this->scope.'&q=teal', ['Authorization' => 'Bearer '.$this->plain])->assertOk();

    $brandHits = collect($response->json('results'))->where('citation.path', 'rules/brand.md');

    expect($brandHits)->toHaveCount(2)
        ->and($brandHits->pluck('anchor')->all())->each->toBeString();
});

it('finds a section that contains only some of the words (#217)', function (): void {
    skipUnlessKeywordSearchSupported();
    $this->getJson('/api/v1/search?scope='.$this->scope.'&q=Inter%20nonexistentword', ['Authorization' => 'Bearer '.$this->plain])
        ->assertOk()
        ->assertJsonPath('mode', 'keyword')
        ->assertJsonPath('results.0.anchor', 'brand/typography');
});

it('merges keyword and semantic hits when embeddings are on, and searches by meaning alone on request (#217)', function (): void {
    skipUnlessKeywordSearchSupported();
    config(['ai.embeddings.enabled' => true, 'ai.embeddings.dimensions' => 2]);
    // Every section and the query embed to the same vector: semantic search ranks all sections.
    Embeddings::fake(fn () => [[1.0, 0.0]]);

    foreach (ItemSection::query()->get() as $section) {
        app(BrainEmbeddingService::class)->embedSection($section);
    }

    $hybrid = $this->getJson('/api/v1/search?scope='.$this->scope.'&q=Inter', ['Authorization' => 'Bearer '.$this->plain])
        ->assertOk()
        ->assertJsonPath('mode', 'hybrid');

    $matches = collect($hybrid->json('results'))->pluck('match');
    expect($hybrid->json('results.0.anchor'))->toBe('brand/typography')
        ->and($matches->contains(fn (string $match): bool => $match !== 'keyword'))->toBeTrue();

    $this->getJson('/api/v1/search?scope='.$this->scope.'&q=colore%20principale&mode=semantic', ['Authorization' => 'Bearer '.$this->plain])
        ->assertOk()
        ->assertJsonPath('mode', 'semantic')
        ->assertJsonPath('results.0.match', 'semantic');
});

it('falls back to keyword search when semantic search is asked for but embeddings are off (#217)', function (): void {
    skipUnlessKeywordSearchSupported();
    $this->getJson('/api/v1/search?scope='.$this->scope.'&q=Inter&mode=semantic', ['Authorization' => 'Bearer '.$this->plain])
        ->assertOk()
        ->assertJsonPath('mode', 'keyword');
});
