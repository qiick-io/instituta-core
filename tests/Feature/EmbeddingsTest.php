<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Jobs\EmbedItemSectionJob;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Services\ApiKeyIssuerService;
use App\Services\BrainEmbeddingService;
use App\Services\PerimeterResolverService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;

function embeddingsSeed(): array
{
    $organisation = Organisation::factory()->create();
    $company = Company::factory()->create(['organisation_id' => $organisation->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $source = Source::factory()->create([
        'organisation_id' => $organisation->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
    ]);
    $principal = Principal::factory()->create(['organisation_id' => $organisation->id]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisation->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    $created = app(ApiKeyIssuerService::class)->create(
        principal: $principal,
        name: 'embeddings',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );

    return [
        'organisation' => $organisation,
        'company' => $company,
        'product' => $product,
        'source' => $source,
        'key' => $created['key'],
        'plain' => $created['plain'],
    ];
}

function embeddingsSection(array $seed, string $heading, string $body): ItemSection
{
    $item = Item::factory()->create([
        'organisation_id' => $seed['organisation']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'source_id' => $seed['source']->id,
        'visibility' => VisibilityEnum::Internal,
        'area' => 'rules',
    ]);

    return ItemSection::factory()->create([
        'item_id' => $item->id,
        'organisation_id' => $seed['organisation']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'heading' => $heading,
        'body' => $body,
        'anchor' => str($heading)->slug()->toString(),
    ]);
}

it('no-ops embed when embeddings are disabled', function (): void {
    config(['ai.embeddings.enabled' => false]);

    Embeddings::fake([
        [[1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0]],
    ]);

    $seed = embeddingsSeed();
    $section = embeddingsSection($seed, 'Brand', 'Calm teal voice');

    app(BrainEmbeddingService::class)->embedSection($section);

    expect($section->fresh()->embedding_vector)->toBeNull();
});

it('stores an embedding vector and model version on success', function (): void {
    config([
        'ai.embeddings.enabled' => true,
        'ai.embeddings.dimensions' => 4,
        'ai.embeddings.model_name' => 'test-embed',
        'ai.embeddings.model_version' => '1',
    ]);

    Embeddings::fake([
        [[0.25, 0.5, 0.75, 1.0]],
    ]);

    $seed = embeddingsSeed();
    $section = embeddingsSection($seed, 'Brand', 'Calm teal voice');

    $embedded = app(BrainEmbeddingService::class)->embedSection($section);

    expect($embedded->embedding_vector)->toEqual([0.25, 0.5, 0.75, 1.0])
        ->and($embedded->embedding_model_id)->not->toBeNull()
        ->and($embedded->embedding_content_hash)->not->toBeEmpty()
        ->and($embedded->embedded_at)->not->toBeNull();
});

it('re-embeds when the active model version changes', function (): void {
    config([
        'ai.embeddings.enabled' => true,
        'ai.embeddings.dimensions' => 2,
        'ai.embeddings.model_name' => 'test-embed',
        'ai.embeddings.model_version' => '1',
    ]);

    Embeddings::fake([
        [[1.0, 0.0]],
        [[0.0, 1.0]],
    ]);

    $seed = embeddingsSeed();
    $section = embeddingsSection($seed, 'Rules', 'Always cite sources');
    $service = app(BrainEmbeddingService::class);
    $service->embedSection($section);

    config(['ai.embeddings.model_version' => '2']);
    $service->embedSection($section->fresh());

    $fresh = $section->fresh();
    expect($fresh->embedding_vector)->toEqual([0.0, 1.0])
        ->and($fresh->embeddingVersion?->version)->toBe('2');
});

it('ranks similar sections inside the perimeter and skips other orgs', function (): void {
    config([
        'ai.embeddings.enabled' => true,
        'ai.embeddings.dimensions' => 2,
    ]);

    Embeddings::fake([
        [[1.0, 0.0]],
        [[0.9, 0.1]],
        [[0.0, 1.0]],
        [[1.0, 0.0]],
    ]);

    $seed = embeddingsSeed();
    $service = app(BrainEmbeddingService::class);
    $near = embeddingsSection($seed, 'Brand voice', 'Teal calm brand');
    $far = embeddingsSection($seed, 'Queues', 'Horizon workers');
    $service->embedSection($near);
    $service->embedSection($far);

    $otherOrganisation = Organisation::factory()->create();
    $otherSource = Source::factory()->create(['organisation_id' => $otherOrganisation->id]);
    $foreignItem = Item::factory()->create([
        'organisation_id' => $otherOrganisation->id,
        'source_id' => $otherSource->id,
        'visibility' => VisibilityEnum::Internal,
    ]);
    $foreign = ItemSection::factory()->create([
        'item_id' => $foreignItem->id,
        'organisation_id' => $otherOrganisation->id,
        'heading' => 'Foreign brand',
        'body' => 'leaked',
    ]);
    $service->embedSection($foreign);

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['key']->fresh('principal'));
    $results = $service->similar($perimeter, 'brand voice', 5);

    expect($results)->not->toBeEmpty()
        ->and(collect($results)->pluck('section_id')->all())->toContain($near->id)
        ->and(collect($results)->pluck('section_id')->all())->not->toContain($foreign->id);
});

it('returns 501 on similar search when embeddings are disabled', function (): void {
    config(['ai.embeddings.enabled' => false]);
    $seed = embeddingsSeed();

    $this->getJson('/api/v1/similar?scope='.$seed['organisation']->id.'&q=brand', [
        'Authorization' => 'Bearer '.$seed['plain'],
    ])->assertStatus(501);
});

it('serves similar search inside the perimeter when enabled', function (): void {
    config([
        'ai.embeddings.enabled' => true,
        'ai.embeddings.dimensions' => 2,
    ]);

    Embeddings::fake([
        [[1.0, 0.0]],
        [[1.0, 0.0]],
    ]);

    $seed = embeddingsSeed();
    $section = embeddingsSection($seed, 'Brand', 'Teal voice');
    app(BrainEmbeddingService::class)->embedSection($section);

    $this->getJson('/api/v1/similar?scope='.$seed['organisation']->id.'&q=brand', [
        'Authorization' => 'Bearer '.$seed['plain'],
    ])->assertOk()
        ->assertJsonPath('query', 'brand')
        ->assertJsonStructure(['results']);
});

it('EmbedItemSectionJob is unique per section id', function (): void {
    $job = new EmbedItemSectionJob('01section');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('01section');
});

it('reports provider failures from the job without writing a vector', function (): void {
    config(['ai.embeddings.enabled' => true]);
    Exceptions::fake();

    Embeddings::fake(function (): never {
        throw new RuntimeException('provider down');
    });

    $seed = embeddingsSeed();
    $section = embeddingsSection($seed, 'X', 'Y');

    (new EmbedItemSectionJob($section->id))->handle(app(BrainEmbeddingService::class));

    expect($section->fresh()->embedding_vector)->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});

it('logs a warning from failed() after retries are exhausted', function (): void {
    Log::spy();

    (new EmbedItemSectionJob('missing'))->failed(new RuntimeException('provider down'));

    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('instituta:embed warns when disabled and embeds when enabled', function (): void {
    config(['ai.embeddings.enabled' => false]);

    $this->artisan('instituta:embed')
        ->expectsOutputToContain('Embeddings are disabled')
        ->assertSuccessful();

    config([
        'ai.embeddings.enabled' => true,
        'ai.embeddings.dimensions' => 2,
    ]);

    Embeddings::fake([
        [[0.5, 0.5]],
    ]);

    $seed = embeddingsSeed();
    $section = embeddingsSection($seed, 'Embed me', 'Body');

    $this->artisan('instituta:embed', ['--section' => $section->id])
        ->assertSuccessful();

    expect($section->fresh()->embedding_vector)->toEqual([0.5, 0.5]);
});
