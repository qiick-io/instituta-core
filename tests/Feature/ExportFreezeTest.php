<?php

declare(strict_types=1);

use App\Enums\OffboardingStatusEnum;
use App\Models\Company;
use App\Models\ContextFreeze;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Models\VaultVersion;
use App\Services\ClientExportService;
use App\Services\ContextFreezeService;
use Illuminate\Support\Facades\File;

it('exports only the client subtree and refuses own companies', function (): void {
    $org = Organisation::factory()->create();
    $clientA = Company::factory()->client()->create([
        'organisation_id' => $org->id,
        'slug' => 'client-a',
        'name' => 'Client A',
    ]);
    $clientB = Company::factory()->client()->create([
        'organisation_id' => $org->id,
        'slug' => 'client-b',
        'name' => 'Client B',
    ]);
    $own = Company::factory()->own()->create(['organisation_id' => $org->id]);

    $productA = Product::factory()->create(['company_id' => $clientA->id]);
    $sourceA = Source::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $clientA->id,
        'product_id' => $productA->id,
        'name' => 'client-a-source',
        'last_synced_commit' => 'aaa111',
    ]);
    $sourceB = Source::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $clientB->id,
        'name' => 'client-b-source',
        'last_synced_commit' => 'bbb222',
    ]);

    Item::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $clientA->id,
        'product_id' => $productA->id,
        'source_id' => $sourceA->id,
        'title' => 'Secret A',
        'path' => 'rules/a.md',
        'stable_key' => 'rules/a',
    ]);
    Item::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $clientB->id,
        'source_id' => $sourceB->id,
        'title' => 'Secret B',
        'path' => 'rules/b.md',
        'stable_key' => 'rules/b',
    ]);

    $dest = storage_path('framework/testing/exports/client-a');
    File::deleteDirectory($dest);

    $result = app(ClientExportService::class)->export($clientA, $dest);

    expect($result['path'])->toBe($dest)
        ->and($result['request']->status)->toBe(OffboardingStatusEnum::Exported)
        ->and($result['manifest']['company']['id'])->toBe($clientA->id)
        ->and($result['manifest']['counts']['items'])->toBe(1)
        ->and(collect($result['manifest']['sources'])->pluck('id')->all())->toBe([$sourceA->id]);

    $index = json_decode(File::get($dest.'/index.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(collect($index['items'])->pluck('title')->all())->toBe(['Secret A'])
        ->and(collect($index['items'])->pluck('title')->all())->not->toContain('Secret B');

    expect(fn () => app(ClientExportService::class)->export($own))
        ->toThrow(InvalidArgumentException::class);

    $this->artisan('instituta:export', [
        'company' => $clientA->slug,
        '--path' => storage_path('framework/testing/exports/client-a-cli'),
    ])->assertSuccessful();
});

it('freezes and unfreezes a vault version via artisan', function (): void {
    $org = Organisation::factory()->create();
    $principal = Principal::factory()->create(['organisation_id' => $org->id]);
    $vault = VaultVersion::factory()->create([
        'organisation_id' => $org->id,
        'label' => 'instituta@v1',
        'source_commits' => ['src' => 'abc'],
    ]);

    $this->artisan('instituta:freeze', [
        'vault' => $vault->id,
        '--principal' => $principal->id,
        '--reason' => 'incident-42',
    ])->assertSuccessful();

    $freeze = ContextFreeze::query()->active()->where('vault_version_id', $vault->id)->first();
    expect($freeze)->not->toBeNull()
        ->and($freeze->reason)->toBe('incident-42');

    $this->artisan('instituta:unfreeze', ['freeze' => $freeze->id])
        ->assertSuccessful();

    expect($freeze->fresh()->lifted_at)->not->toBeNull();

    $again = app(ContextFreezeService::class)->freeze(
        vault: $vault,
        principal: $principal,
        reason: 'second',
    );
    expect(ContextFreeze::query()->active()->where('organisation_id', $org->id)->count())->toBe(1)
        ->and($again->reason)->toBe('second');
});

it('answers health live and ready', function (): void {
    $this->getJson('/health/live')->assertOk()->assertJson(['status' => 'ok']);
    $this->getJson('/health/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', 'ok');
});

it('runs instituta:upgrade migrate path', function (): void {
    $this->artisan('instituta:upgrade', ['--no-migrate' => true])
        ->assertSuccessful();
});
