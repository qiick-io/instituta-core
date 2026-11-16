<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Brain\Enums\ItemStatusEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceProviderEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\File;

/**
 * Agents can tell whether an entry is still valid (#218).
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->company = Company::factory()->create(['organisation_id' => $this->organisation->id]);
    $this->product = Product::factory()->create(['company_id' => $this->company->id]);

    $principal = Principal::factory()->create(['organisation_id' => $this->organisation->id]);
    Grant::factory()->create(['principal_id' => $principal->id, 'scope_type' => ScopeTypeEnum::Organisation, 'scope_id' => $this->organisation->id, 'role' => RoleEnum::Owner, 'max_visibility' => VisibilityEnum::Restricted]);
    $this->auth = ['Authorization' => 'Bearer '.app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: 'validity',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $this->organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    )['plain']];
});

/**
 * @param  array<string, string>  $files  path => markdown
 */
function syncValidityBrain(Organisation $organisation, array $files): string
{
    $path = storage_path('framework/testing/validity-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $path);
    foreach ($files as $file => $markdown) {
        File::put($path.'/'.$file, $markdown);
    }

    $source = app(SourceRegistryService::class)->add($organisation, 'Validity fixture', SourceProviderEnum::Local, localPath: $path, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($source->fresh());

    return $path;
}

afterEach(function (): void {
    foreach (File::glob(storage_path('framework/testing/validity-*')) as $directory) {
        File::deleteDirectory($directory);
    }
});

it('reads status and superseded_by at sync and exposes validity in reads', function (): void {
    syncValidityBrain($this->organisation, [
        'rules/old-naming.md' => "---\ntitle: Old naming\nstatus: superseded\nsuperseded_by: rules/naming\nreview_date: '2020-01-01'\n---\n\n# Old naming\n\nAbbreviations are fine.\n",
        'rules/naming.md' => "---\ntitle: Naming\n---\n\n# Naming\n\nFull words, no abbreviations.\n",
        'rules/legacy.md' => "---\ntitle: Legacy\nsuperseded_by: rules/naming\n---\n\n# Legacy\n\nOld text.\n",
    ]);

    $old = Item::query()->where('path', 'rules/old-naming.md')->sole();

    expect($old->status)->toBe(ItemStatusEnum::Superseded)
        ->and($old->superseded_by)->toBe('rules/naming')
        ->and(Item::query()->where('path', 'rules/naming.md')->sole()->status)->toBe(ItemStatusEnum::Active)
        ->and(Item::query()->where('path', 'rules/legacy.md')->sole()->status)->toBe(ItemStatusEnum::Superseded);

    $this->getJson('/api/v1/items/'.$old->id, $this->auth)
        ->assertOk()
        ->assertJsonPath('status', 'superseded')
        ->assertJsonPath('superseded_by', 'rules/naming')
        ->assertJsonPath('review_date', '2020-01-01')
        ->assertJsonPath('is_review_overdue', true)
        ->assertJsonPath('frontmatter.superseded_by', 'rules/naming');
});

it('lists active entries before superseded ones in search', function (): void {
    syncValidityBrain($this->organisation, [
        'rules/old-naming.md' => "---\ntitle: Naming abbreviations naming\nstatus: superseded\nsuperseded_by: rules/naming\n---\n\n# Naming abbreviations naming\n\nNaming naming naming: abbreviations are fine.\n",
        'rules/naming.md' => "---\ntitle: Naming\n---\n\n# Naming\n\nFull words.\n",
    ]);

    $results = $this->getJson('/api/v1/search?scope='.$this->organisation->id.'&q=naming', $this->auth)->assertOk()->json('results');
    $statuses = array_column($results, 'status');

    expect($statuses[0])->toBe('active')
        ->and($statuses)->toContain('superseded')
        ->and(array_search('superseded', $statuses, true))->toBeGreaterThan(array_search('active', $statuses, true));
});

it('never lets a superseded product entry win over an active organisation entry when resolving', function (): void {
    $orgSource = Source::factory()->create(['organisation_id' => $this->organisation->id]);
    $productSource = Source::factory()->create(['organisation_id' => $this->organisation->id, 'company_id' => $this->company->id, 'product_id' => $this->product->id]);
    Item::factory()->create(['organisation_id' => $this->organisation->id, 'source_id' => $orgSource->id, 'resolution_key' => 'brand.primary', 'title' => 'Teal (current)', 'path' => 'brand/primary.md', 'visibility' => VisibilityEnum::Internal]);
    Item::factory()->create(['organisation_id' => $this->organisation->id, 'company_id' => $this->company->id, 'product_id' => $this->product->id, 'source_id' => $productSource->id, 'resolution_key' => 'brand.primary', 'title' => 'Orange (old)', 'path' => 'brand/old-primary.md', 'status' => ItemStatusEnum::Superseded, 'visibility' => VisibilityEnum::Internal]);
    Item::factory()->create(['organisation_id' => $this->organisation->id, 'company_id' => $this->company->id, 'product_id' => $this->product->id, 'source_id' => $productSource->id, 'resolution_key' => 'brand.voice', 'title' => 'Voice (deprecated, alone)', 'path' => 'brand/voice.md', 'status' => ItemStatusEnum::Deprecated, 'visibility' => VisibilityEnum::Internal]);

    $entries = collect($this->getJson('/api/v1/products/'.$this->product->id.'/resolved', $this->auth)->assertOk()->json('entries'))->keyBy('resolution_key');

    expect($entries['brand.primary']['title'])->toBe('Teal (current)')
        ->and($entries['brand.primary']['status'])->toBe('active')
        ->and($entries['brand.voice']['status'])->toBe('deprecated');

    $inactive = collect($this->getJson('/api/v1/products/'.$this->product->id.'/resolved?include_inactive=1', $this->auth)->json('entries'))->keyBy('resolution_key');
    expect($inactive['brand.primary']['title'])->toBe('Orange (old)');
});
