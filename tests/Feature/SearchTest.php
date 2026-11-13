<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Contracts\SearchIndexContract;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Services\IndexedContractService;
use App\Services\PerimeterResolverService;
use App\Services\Search\UnsupportedSearchDriver;
use Illuminate\Support\Facades\DB;

function searchSeed(): array
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

    $created = app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: 'search',
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

function searchIndexItem(array $seed, string $title, string $body, string $path): Item
{
    $item = Item::factory()->create([
        'organisation_id' => $seed['org']->id,
        'company_id' => $seed['company']->id,
        'product_id' => $seed['product']->id,
        'source_id' => $seed['source']->id,
        'title' => $title,
        'summary' => $title,
        'visibility' => VisibilityEnum::Internal,
        'area' => 'rules',
        'path' => $path,
        'stable_key' => str_replace('.md', '', $path),
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
        'body' => $body,
        'blocks' => [['type' => 'paragraph', 'text' => $body]],
    ]);

    return $item;
}

it('ranks matching items inside the perimeter on the active driver', function (): void {
    $driver = DB::connection()->getDriverName();
    expect(in_array($driver, ['sqlite', 'pgsql'], true))->toBeTrue();

    $seed = searchSeed();
    $hit = searchIndexItem($seed, 'Brand voice', 'Use a calm teal brand voice in every product.', 'rules/brand.md');
    searchIndexItem($seed, 'Queue workers', 'Horizon queues and failed jobs.', 'rules/queues.md');

    $orgB = Organisation::factory()->create();
    $sourceB = Source::factory()->create(['organisation_id' => $orgB->id]);
    $foreign = Item::factory()->create([
        'organisation_id' => $orgB->id,
        'source_id' => $sourceB->id,
        'title' => 'Foreign brand',
        'summary' => 'brand secret',
        'path' => 'rules/foreign-brand.md',
        'stable_key' => 'rules/foreign-brand',
        'visibility' => VisibilityEnum::Internal,
    ]);
    ItemSection::factory()->create([
        'item_id' => $foreign->id,
        'organisation_id' => $orgB->id,
        'anchor' => 'body',
        'heading' => 'Body',
        'body' => 'brand voice leaked across tenants',
    ]);

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['key']->fresh('principal'));
    $payload = app(IndexedContractService::class)->search(
        $perimeter,
        $seed['key'],
        'brand voice',
    );

    $ids = collect($payload['results'])->pluck('id')->all();
    expect($ids)->toContain($hit->id)
        ->and($ids)->not->toContain($foreign->id)
        ->and($payload['results'][0]['snippet'])->not->toBe('');
});

it('refuses search on unsupported drivers', function (): void {
    $this->app->bind(SearchIndexContract::class, fn (): SearchIndexContract => new UnsupportedSearchDriver);

    $seed = searchSeed();
    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['key']->fresh('principal'));

    expect(fn () => app(IndexedContractService::class)->search(
        $perimeter,
        $seed['key'],
        'brand',
    ))->toThrow(RuntimeException::class, 'search driver not supported');
});

it('returns 501 on REST when the search driver is unsupported', function (): void {
    $this->app->bind(SearchIndexContract::class, fn (): SearchIndexContract => new UnsupportedSearchDriver);

    $seed = searchSeed();

    $this->getJson(
        '/api/v1/search?scope='.$seed['org']->id.'&q=brand',
        ['Authorization' => 'Bearer '.$seed['plain']],
    )->assertStatus(501)
        ->assertJsonPath('message', 'search driver not supported');
});
