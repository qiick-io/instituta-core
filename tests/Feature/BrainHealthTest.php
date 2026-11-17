<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceProviderEnum;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Tools\Http\InstitutaHealthTool;
use App\Models\ApiKey;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use App\Services\PerimeterResolverService;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The brain's health after a sync, for owners (#231).
 */
beforeEach(function (): void {
    $this->withoutVite();
    config(['instituta.mcp.enabled' => true]);

    $this->brainPath = storage_path('framework/testing/health-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $this->brainPath);
    File::put($this->brainPath.'/rules/linked.md', "---\ntitle: Linked\narea: rules\n---\n\n# Linked\n\nSee [the missing rule](missing-rule.md), [the site](https://example.com) and [the logo](logo.png).\n");
    File::put($this->brainPath.'/rules/old-voice.md', "---\ntitle: Old voice\narea: rules\nstatus: superseded\nsuperseded_by: rules/no-such-voice\n---\n\n# Old voice\n\nFormal.\n");
    File::put($this->brainPath.'/rules/coding-copy.md', "---\ntitle: Coding copy\narea: rules\nresolution_key: rules.coding\n---\n\n# Coding copy\n\nAnother take.\n");
    File::put($this->brainPath.'/rules/stale.md', "---\ntitle: Stale\narea: rules\nreview_date: '2020-01-01'\n---\n\n# Stale\n\nOld rule.\n");
    // Two files the sync always skips (a private key in the body), reported once as a group.
    File::put($this->brainPath.'/rules/leaked-one.md', "# Leaked\n\n-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC\n-----END PRIVATE KEY-----\n");
    File::put($this->brainPath.'/rules/leaked-two.md', "# Leaked too\n\n-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC\n-----END PRIVATE KEY-----\n");
    File::put($this->brainPath.'/rules/broken.md', "---\ntitle: [unclosed\n---\n\n# Broken\n");

    $this->organisation = Organisation::factory()->create();
    $source = app(SourceRegistryService::class)->add($this->organisation, 'Fixture', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($source->fresh());

    $other = Organisation::factory()->create();
    $otherSource = app(SourceRegistryService::class)->add($other, 'Other', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($otherSource->fresh());
});

afterEach(function (): void {
    File::deleteDirectory($this->brainPath);
});

/**
 * @return array{plain: string, key: ApiKey}
 */
function healthKey(Organisation $organisation, RoleEnum $role): array
{
    $principal = Principal::factory()->create(['organisation_id' => $organisation->id]);
    Grant::factory()->create(['principal_id' => $principal->id, 'scope_type' => ScopeTypeEnum::Organisation, 'scope_id' => $organisation->id, 'role' => $role, 'max_visibility' => VisibilityEnum::Restricted]);

    return app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: 'health',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $organisation->id,
        role: $role,
        maxVisibility: VisibilityEnum::Restricted,
    );
}

it('finds each kind of problem with its item, path and a suggested fix', function (): void {
    $health = $this->getJson('/api/v1/health/brain', ['Authorization' => 'Bearer '.healthKey($this->organisation, RoleEnum::Owner)['plain']])
        ->assertOk()
        ->json();

    $byCode = collect($health['issues'])->groupBy('code');

    expect($byCode->keys()->all())->toContain('broken_link', 'missing_superseded_by', 'duplicate_resolution_key', 'review_overdue', 'reader_issue')
        ->and($linkedIssues = collect($byCode['broken_link'])->where('path', 'rules/linked.md')->values())->toHaveCount(1)
        ->and($linkedIssues[0]['stable_key'])->toBe('rules/linked')
        ->and($linkedIssues[0]['message'])->toContain('missing-rule.md')
        ->and($byCode['missing_superseded_by'][0]['path'])->toBe('rules/old-voice.md')
        ->and(collect($byCode['duplicate_resolution_key'])->pluck('path')->sort()->values()->all())->toBe(['rules/coding-copy.md', 'rules/coding.md'])
        ->and(collect($byCode['review_overdue'])->pluck('path')->all())->toContain('rules/stale.md')
        ->and(collect($byCode['reader_issue'])->pluck('path')->all())->toContain('rules/broken.md')
        ->and(collect($byCode['skipped_file'] ?? [])->pluck('message')->filter(fn (string $message): bool => str_contains($message, 'leaked-one.md') || str_contains($message, 'leaked-two.md'))->count())->toBe(1)
        ->and($health['issues'][0]['suggested_fix'])->not->toBe('')
        ->and($health['error_count'] + $health['warning_count'])->toBe(count($health['issues']));
});

it('is for owners only, and never counts another tenant\'s problems', function (): void {
    $this->getJson('/api/v1/health/brain', ['Authorization' => 'Bearer '.healthKey($this->organisation, RoleEnum::Member)['plain']])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    $cleanOrganisation = Organisation::factory()->create();
    $this->getJson('/api/v1/health/brain', ['Authorization' => 'Bearer '.healthKey($cleanOrganisation, RoleEnum::Owner)['plain']])
        ->assertOk()
        ->assertJsonPath('issues', [])
        ->assertJsonPath('error_count', 0);
});

it('shows the health on the dashboard to owners only', function (): void {
    $owner = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($owner, $this->organisation, RoleEnum::Owner);
    $member = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($member, $this->organisation, RoleEnum::Member);

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('health.issues', fn ($issues): bool => count($issues) > 0));

    $this->actingAs($member)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('health', null));
});

it('serves the health over MCP to owner keys', function (): void {
    $setKey = function (ApiKey $key): void {
        request()->attributes->set('instituta.api_key', $key->fresh('principal'));
        request()->attributes->set('instituta.perimeter', app(PerimeterResolverService::class)->fromApiKey($key->fresh('principal')));
    };

    $setKey(healthKey($this->organisation, RoleEnum::Owner)['key']);
    HttpBrainServer::tool(InstitutaHealthTool::class)->assertOk()->assertSee(['broken_link', 'rules/linked.md']);

    $setKey(healthKey($this->organisation, RoleEnum::Member)['key']);
    HttpBrainServer::tool(InstitutaHealthTool::class)->assertHasErrors(['[forbidden]']);
});
