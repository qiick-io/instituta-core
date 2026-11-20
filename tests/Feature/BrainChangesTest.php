<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceProviderEnum;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Tools\Http\InstitutaChangesTool;
use App\Models\Grant;
use App\Models\ItemChange;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Source;
use App\Models\VaultVersion;
use App\Services\PerimeterResolverService;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * What changed in the brain since a date or version (#228).
 */
beforeEach(function (): void {
    config(['instituta.mcp.enabled' => true]);

    $this->brainPath = storage_path('framework/testing/changes-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $this->brainPath);
    File::put($this->brainPath.'/rules/brand.md', "---\ntitle: Brand\narea: rules\n---\n\n# Brand\n\nThe brand.\n\n## Colours\n\nPrimary: teal.\n\n## Typography\n\nInter.\n");

    Carbon::setTestNow('2026-11-16 10:00:00');
    $otherOrganisation = Organisation::factory()->create();
    $otherSource = app(SourceRegistryService::class)->add($otherOrganisation, 'Other', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($otherSource->fresh());

    $this->organisation = Organisation::factory()->create();
    $this->source = app(SourceRegistryService::class)->add($this->organisation, 'Brand fixture', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($this->source->fresh());

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
    Carbon::setTestNow();
    File::deleteDirectory($this->brainPath);
});

/**
 * Edits one section, adds a file and removes another, then syncs again an hour later.
 */
function changeTheBrain(): void
{
    $path = test()->brainPath;
    File::put($path.'/rules/brand.md', "---\ntitle: Brand\narea: rules\n---\n\n# Brand\n\nThe brand.\n\n## Colours\n\nPrimary: blue.\n\n## Typography\n\nInter.\n");
    File::put($path.'/rules/voice.md', "---\ntitle: Voice\narea: rules\n---\n\n# Voice\n\nCalm.\n");
    File::delete($path.'/decisions/one-repo.md');

    Carbon::setTestNow('2026-11-16 11:00:00');
    app(SourceSyncService::class)->sync(test()->source->fresh());
}

it('lists the items created, updated and removed since the previous sync', function (): void {
    $previousVersion = VaultVersion::query()->where('organisation_id', $this->organisation->id)->sole()->label;
    changeTheBrain();

    $changes = $this->getJson('/api/v1/changes?'.http_build_query(['scope' => $this->organisation->id, 'since' => $previousVersion]), $this->headers)
        ->assertOk()
        ->json('changes');

    $byKey = collect($changes)->keyBy('stable_key');

    expect($changes)->toHaveCount(3)
        ->and($byKey['rules/brand']['kind'])->toBe('updated')
        ->and($byKey['rules/brand']['changed_sections'])->toBe(['brand/colours'])
        ->and($byKey['rules/brand']['citation']['path'])->toBe('rules/brand.md')
        ->and($byKey['rules/voice']['kind'])->toBe('created')
        ->and($byKey['decisions/one-repo']['kind'])->toBe('removed')
        ->and($byKey['decisions/one-repo']['status'])->toBe('active');

    $sinceDate = $this->getJson('/api/v1/changes?'.http_build_query(['scope' => $this->organisation->id, 'since' => '2026-11-16T10:30:00Z']), $this->headers)
        ->json('changes');
    expect($sinceDate)->toHaveCount(3);

    expect(ItemChange::query()->whereNot('organisation_id', $this->organisation->id)->where('changed_at', '>', '2026-11-16 10:30:00')->count())->toBe(0);
});

it('records nothing when a forced sync finds nothing new', function (): void {
    $before = ItemChange::query()->count();

    Carbon::setTestNow('2026-11-16 12:00:00');
    app(SourceSyncService::class)->sync($this->source->fresh());

    expect(ItemChange::query()->count())->toBe($before);
});

it('pages the feed and never shows another tenant\'s changes', function (): void {
    $first = $this->getJson('/api/v1/changes?'.http_build_query(['scope' => $this->organisation->id, 'since' => '2026-01-01', 'limit' => 2]), $this->headers)
        ->assertOk();
    $initialCount = ItemChange::query()->where('organisation_id', $this->organisation->id)->count();

    expect($first->json('changes'))->toHaveCount(2)
        ->and($first->json('next_cursor'))->not->toBeNull();

    $rest = $this->getJson('/api/v1/changes?'.http_build_query(['scope' => $this->organisation->id, 'since' => '2026-01-01', 'cursor' => $first->json('next_cursor'), 'limit' => 200]), $this->headers)
        ->json('changes');

    $otherSourceId = Source::query()->whereNot('organisation_id', $this->organisation->id)->sole()->id;
    $all = [...$first->json('changes'), ...$rest];
    expect($all)->toHaveCount($initialCount)
        ->and(array_unique(array_column(array_column($all, 'citation'), 'source')))->not->toContain($otherSourceId);
});

it('refuses a bad since and an unknown version', function (): void {
    $this->getJson('/api/v1/changes?'.http_build_query(['scope' => $this->organisation->id, 'since' => 'last tuesday-ish!!']), $this->headers)
        ->assertUnprocessable()
        ->assertJsonPath('error', 'validation_failed');

    $this->getJson('/api/v1/changes?'.http_build_query(['scope' => $this->organisation->id, 'since' => 'instituta@v999']), $this->headers)
        ->assertNotFound()
        ->assertJsonPath('error', 'not_found');
});

it('serves the change feed over MCP', function (): void {
    changeTheBrain();
    request()->attributes->set('instituta.api_key', $this->key->fresh('principal'));
    request()->attributes->set('instituta.perimeter', app(PerimeterResolverService::class)->fromApiKey($this->key->fresh('principal')));

    HttpBrainServer::tool(InstitutaChangesTool::class, ['since' => '2026-11-16T10:30:00Z'])
        ->assertOk()
        ->assertSee(['rules/voice', 'removed', 'brand/colours']);

    HttpBrainServer::tool(InstitutaChangesTool::class, ['since' => 'not a date'])->assertHasErrors(['[validation_failed]']);
    HttpBrainServer::tool(InstitutaChangesTool::class, [])->assertHasErrors(['[validation_failed]']);
});
