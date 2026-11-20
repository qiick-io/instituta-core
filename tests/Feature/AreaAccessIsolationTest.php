<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Data\PerimeterData;
use App\Brain\Enums\RoleEnum as BrainRoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\GapReportKindEnum;
use App\Enums\GapReportStatusEnum;
use App\Enums\ProposalStatusEnum;
use App\Enums\RoleEnum;
use App\Enums\SourceProviderEnum;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Tools\Http\InstitutaContextTool;
use App\Mcp\Tools\Http\InstitutaGraphTool;
use App\Mcp\Tools\Http\InstitutaSearchTool;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\GapReport;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\User;
use App\Models\UserGroup;
use App\Notifications\ProposalOpenedNotification;
use App\Services\ActivityLogQueryService;
use App\Services\BrainClientsService;
use App\Services\BrainHealthService;
use App\Services\BrainMapService;
use App\Services\GapReportQueryService;
use App\Services\NotifyOrganisationUsersService;
use App\Services\PerimeterResolverService;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Testing\TestResponse;

/**
 * Per-area access (#334): a "Developers" group limited to rules, decisions and the design system
 * never sees the title, summary or id of the style entry, on any surface that can return an item
 * (UI pages and their shared props, REST, MCP, counts, notifications, organisation-wide owner
 * pages). The brain is synced from files, so links, backlinks, search and the change log are real.
 *
 * Next to CrossTenantIsolationTest, which proves the same across organisations.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    config(['instituta.mcp.enabled' => true]);

    $this->brainPath = storage_path('framework/testing/area-access-'.uniqid());
    File::ensureDirectoryExists($this->brainPath.'/rules');
    File::ensureDirectoryExists($this->brainPath.'/style');
    File::put($this->brainPath.'/rules/handbook.md', "---\ntitle: Rules handbook\narea: rules\nresolution_key: rules.handbook\nsummary: Visible teal summary\n---\n\n# Rules handbook\n\nTeal rules. See [the voice](../style/voice.md).\n");
    File::put($this->brainPath.'/style/voice.md', "---\ntitle: Style secret\narea: style\nresolution_key: style.voice\nsummary: Hidden teal summary\n---\n\n# Style secret\n\nTeal voice. Back to [the handbook](../rules/handbook.md).\n");

    $this->organisation = Organisation::factory()->create(['name' => 'Qiick']);
    $this->source = app(SourceRegistryService::class)->add($this->organisation, 'Brain', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null);
    app(SourceSyncService::class)->sync($this->source->fresh());
    $this->product = Product::factory()->create([
        'company_id' => Company::factory()->create(['organisation_id' => $this->organisation->id])->id,
        'inherits_from_parent' => true,
    ]);

    $this->visibleItem = Item::query()->where('stable_key', 'rules/handbook')->sole();
    $this->hiddenItem = Item::query()->where('stable_key', 'style/voice')->sole();

    $this->admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    app(GrantOrganisationAccessAction::class)->execute($this->admin, $this->organisation, BrainRoleEnum::Owner);

    $this->developer = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    $this->actingAs($this->admin)->post(route('groups.store'), [
        'name' => 'Developers',
        'user_ids' => [$this->developer->id],
        'brain_scope' => 'organisation:'.$this->organisation->id,
        'brain_role' => 'member',
        'brain_areas' => ['rules', 'decisions', 'design-system'],
    ])->assertSessionHasNoErrors();

    $this->developerPrincipal = Principal::query()->where('user_id', $this->developer->id)->sole();
    $this->developerKey = app(CreateApiKeyAction::class)->execute(
        principal: $this->developerPrincipal,
        name: 'developer agent',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $this->organisation->id,
        role: BrainRoleEnum::Member,
        maxVisibility: VisibilityEnum::Internal,
    );
    $this->apiHeaders = ['Authorization' => 'Bearer '.$this->developerKey['plain']];
});

afterEach(function (): void {
    File::deleteDirectory($this->brainPath);
});

/**
 * Fails when a response body or payload names the hidden style entry in any way.
 */
function assertNothingOfTheHiddenItem(TestResponse|array|string $body): void
{
    $text = match (true) {
        $body instanceof TestResponse => (string) $body->getContent(),
        is_array($body) => (string) json_encode($body),
        default => $body,
    };

    expect($text)->not->toContain('Style secret')
        ->and($text)->not->toContain('Hidden teal summary')
        ->and($text)->not->toContain(test()->hiddenItem->id);
}

/**
 * Points the MCP tools at the developer's key, as the MCP middleware does.
 */
function actAsDeveloperKeyOverMcp(): void
{
    $key = test()->developerKey['key']->fresh('principal');
    request()->attributes->set('instituta.api_key', $key);
    request()->attributes->set('instituta.perimeter', app(PerimeterResolverService::class)->fromApiKey($key));
}

/**
 * A brain:write key of the developer (proposals are behind it on REST), with REST writes on.
 *
 * @return array{Authorization: string}
 */
function developerWriteHeaders(): array
{
    config(['instituta.api.write_enabled' => true]);
    $writeKey = app(CreateApiKeyAction::class)->execute(
        principal: test()->developerPrincipal,
        name: 'developer writer',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: test()->organisation->id,
        role: BrainRoleEnum::Member,
        maxVisibility: VisibilityEnum::Internal,
        scopes: [ApiKeyScopeEnum::BrainWrite->value],
    );

    return ['Authorization' => 'Bearer '.$writeKey['plain']];
}

it('syncs a real link in both directions, so the link surfaces are exercised', function (): void {
    expect(ItemLink::query()->where('from_item_id', $this->visibleItem->id)->value('to_item_id'))->toBe($this->hiddenItem->id)
        ->and(ItemLink::query()->where('from_item_id', $this->hiddenItem->id)->value('to_item_id'))->toBe($this->visibleItem->id);
});

it('gives the group member a perimeter limited to the group areas', function (): void {
    $perimeter = app(PerimeterResolverService::class)->fromApiKey($this->developerKey['key']->fresh('principal'));

    expect($perimeter->areas)->toBe(['rules', 'decisions', 'design-system'])
        ->and($perimeter->pathPrefixes)->toBe([]);

    $this->getJson('/api/v1/scopes', $this->apiHeaders)
        ->assertOk()
        ->assertJsonPath('perimeter.areas', ['rules', 'decisions', 'design-system']);
});

it('keeps the explorer tree, its total and the reading view inside the areas', function (): void {
    $tree = $this->actingAs($this->developer)->get(route('explorer.index'))->assertOk();
    assertNothingOfTheHiddenItem($tree);
    $tree->assertInertia(fn ($page) => $page->where('total', 1));

    $this->actingAs($this->developer)->get(route('explorer.show', ['item' => $this->hiddenItem->id]))->assertNotFound();
});

it('renders a link to an item outside the areas as plain text, without its id', function (): void {
    $reading = $this->actingAs($this->developer)->get(route('explorer.show', ['item' => $this->visibleItem->id]))->assertOk();

    assertNothingOfTheHiddenItem($reading);
});

it('keeps the graph, its preview card and the neighbours inside the areas', function (): void {
    assertNothingOfTheHiddenItem($this->actingAs($this->developer)->get(route('graph'))->assertOk());
    assertNothingOfTheHiddenItem($this->actingAs($this->developer)->getJson(route('graph.preview', ['item' => $this->visibleItem->id]))->assertOk());
    $this->actingAs($this->developer)->getJson(route('graph.preview', ['item' => $this->hiddenItem->id]))->assertNotFound();

    assertNothingOfTheHiddenItem($this->getJson('/api/v1/items/'.$this->visibleItem->id.'/neighbours?depth=2', $this->apiHeaders)->assertOk());
    $this->getJson('/api/v1/items/'.$this->hiddenItem->id.'/neighbours', $this->apiHeaders)->assertOk()->assertJsonPath('nodes', []);

    actAsDeveloperKeyOverMcp();
    HttpBrainServer::tool(InstitutaGraphTool::class, ['id' => $this->visibleItem->id, 'depth' => 2])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json): void {
            assertNothingOfTheHiddenItem($json->toArray());
            $json->etc();
        });
});

it('keeps the resolved view inside the areas, in the UI and over REST', function (): void {
    assertNothingOfTheHiddenItem($this->actingAs($this->developer)->get(route('resolved', ['product' => $this->product->id]))->assertOk());

    $resolved = $this->getJson('/api/v1/products/'.$this->product->id.'/resolved', $this->apiHeaders)->assertOk();
    assertNothingOfTheHiddenItem($resolved);
    expect(collect($resolved->json('entries'))->pluck('resolution_key')->all())->toBe(['rules.handbook']);
});

it('keeps REST list, get, outline and changes inside the areas', function (): void {
    assertNothingOfTheHiddenItem($this->getJson('/api/v1/items?scope='.$this->organisation->id, $this->apiHeaders)->assertOk());
    $this->getJson('/api/v1/items/'.$this->hiddenItem->id, $this->apiHeaders)->assertNotFound();
    $this->getJson('/api/v1/items/'.$this->hiddenItem->id.'/outline', $this->apiHeaders)->assertNotFound();
    assertNothingOfTheHiddenItem($this->getJson('/api/v1/items/'.$this->visibleItem->id, $this->apiHeaders)->assertOk());

    $changes = $this->getJson('/api/v1/changes?scope='.$this->organisation->id.'&since=2000-01-01T00:00:00Z', $this->apiHeaders)->assertOk();
    assertNothingOfTheHiddenItem($changes);
    expect($changes->json('changes'))->toHaveCount(1);
});

it('keeps search hits and their number inside the areas, over REST and MCP', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
        $this->markTestSkipped('Keyword search runs on SQLite and PostgreSQL only (#313).');
    }

    $search = $this->getJson('/api/v1/search?scope='.$this->organisation->id.'&q=teal', $this->apiHeaders)->assertOk();
    assertNothingOfTheHiddenItem($search);
    expect(collect($search->json('results'))->pluck('id')->unique()->values()->all())->toBe([$this->visibleItem->id]);

    actAsDeveloperKeyOverMcp();
    HttpBrainServer::tool(InstitutaSearchTool::class, ['q' => 'teal'])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json): void {
            assertNothingOfTheHiddenItem($json->toArray());
            $json->etc();
        });

    $palette = $this->actingAs($this->developer)->getJson(route('search', ['query' => 'secret']))->assertOk();
    assertNothingOfTheHiddenItem($palette);
});

it('keeps instituta_context inside the areas, over REST and MCP', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
        $this->markTestSkipped('instituta_context searches, which runs on SQLite and PostgreSQL only (#313).');
    }

    $context = $this->getJson('/api/v1/context?'.http_build_query(['scope' => $this->organisation->id, 'q' => 'teal', 'product_id' => $this->product->id]), $this->apiHeaders)->assertOk();
    assertNothingOfTheHiddenItem($context);
    expect($context->json('sections'))->not->toBeEmpty();

    actAsDeveloperKeyOverMcp();
    HttpBrainServer::tool(InstitutaContextTool::class, ['q' => 'teal', 'product_id' => $this->product->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json): void {
            assertNothingOfTheHiddenItem($json->toArray());
            $json->etc();
        });
});

it('counts only the allowed areas on the dashboard and the area map', function (): void {
    $dashboard = $this->actingAs($this->developer)->get(route('dashboard'))->assertOk();
    assertNothingOfTheHiddenItem($dashboard);
    $dashboard->assertInertia(fn ($page) => $page
        ->where('contentStats.items_count', 1)
        ->where('sources.0.items_count', 1)
        ->where('areaCounts', [['area' => 'rules', 'count' => 1]]));

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($this->developerKey['key']->fresh('principal'));
    expect(array_column(app(BrainMapService::class)->areas($perimeter), 'area'))->toBe(['rules', 'decisions', 'design-system']);
});

it('keeps proposals on an item outside the areas out of the lists, the detail and the review badge', function (): void {
    $hiddenProposal = Proposal::factory()->create([
        'organisation_id' => $this->organisation->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $this->organisation->id,
        'stable_key' => 'style/voice',
        'title' => 'Style secret',
        'path' => 'style/voice.md',
        'area' => null,
        'status' => ProposalStatusEnum::Open,
    ]);
    Proposal::factory()->create([
        'organisation_id' => $this->organisation->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $this->organisation->id,
        'stable_key' => 'rules/handbook',
        'title' => 'Rules handbook',
        'path' => 'rules/handbook.md',
        'area' => 'rules',
        'status' => ProposalStatusEnum::Open,
    ]);

    $list = $this->actingAs($this->developer)->get(route('review.proposals.index'))->assertOk();
    assertNothingOfTheHiddenItem($list);
    expect((string) $list->getContent())->not->toContain($hiddenProposal->id);
    $list->assertInertia(fn ($page) => $page->where('review.open_proposals', 1));

    $this->actingAs($this->developer)->get(route('review.proposals.show', $hiddenProposal))->assertNotFound();
    $this->getJson('/api/v1/proposals?scope='.$this->organisation->id, developerWriteHeaders())
        ->assertOk()
        ->assertJsonCount(1, 'proposals');
});

it('refuses a new item proposed outside the areas', function (): void {
    $this->postJson('/api/v1/proposals', [
        'action' => 'create',
        'stable_key' => 'style/tone',
        'title' => 'Tone',
        'path' => 'style/tone.md',
        'area' => 'style',
        'body' => '# Tone',
    ], developerWriteHeaders())
        ->assertUnprocessable()
        ->assertJsonPath('error', 'out_of_perimeter');

    expect(Proposal::query()->count())->toBe(0);
});

it('notifies only the people who can read the item a proposal changes', function (): void {
    Notification::fake();
    $proposal = Proposal::factory()->create([
        'organisation_id' => $this->organisation->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $this->organisation->id,
        'stable_key' => 'style/voice',
        'title' => 'Style secret',
        'path' => 'style/voice.md',
        'area' => null,
    ]);

    app(NotifyOrganisationUsersService::class)->notifyAboutProposal($proposal, new ProposalOpenedNotification($proposal));

    Notification::assertSentTo($this->admin, ProposalOpenedNotification::class);
    Notification::assertNotSentTo($this->developer, ProposalOpenedNotification::class);
});

it('shows a limited owner only the gap reports and health issues of their areas', function (): void {
    $leadPrincipal = Principal::factory()->create(['organisation_id' => $this->organisation->id]);
    $leadGrant = Grant::factory()->owner()->create([
        'principal_id' => $leadPrincipal->id,
        'scope_id' => $this->organisation->id,
        'areas' => ['rules'],
    ]);
    $perimeter = app(PerimeterResolverService::class)->fromGrant($leadPrincipal, $leadGrant);
    $related = static fn (Item $item): array => ['item' => $item->id, 'stable_key' => $item->stable_key, 'path' => $item->path, 'title' => $item->title, 'anchor' => null];

    foreach ([[$this->hiddenItem], [$this->visibleItem, $this->hiddenItem]] as $items) {
        GapReport::query()->create([
            'organisation_id' => $this->organisation->id,
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => $this->organisation->id,
            'kind' => GapReportKindEnum::Missing,
            'summary' => 'Something is missing',
            'related' => array_map($related, $items),
            'reported_by_principal_id' => $leadPrincipal->id,
        ]);
    }

    $gapReports = app(GapReportQueryService::class);
    assertNothingOfTheHiddenItem($gapReports->listForUi($perimeter, GapReportStatusEnum::Open));
    expect($gapReports->openCount($perimeter))->toBe(1);

    $this->source->forceFill(['last_sync_report' => ['skipped' => [['path' => 'style/Style secret.txt', 'reason' => 'unsupported']]]])->save();
    assertNothingOfTheHiddenItem(app(BrainHealthService::class)->check($perimeter));
});

it('keeps an owner limited to some areas out of the organisation-wide owner pages', function (): void {
    $lead = User::factory()->create();
    $this->actingAs($this->admin)->post(route('groups.store'), [
        'name' => 'Leads',
        'user_ids' => [$lead->id],
        'brain_scope' => 'organisation:'.$this->organisation->id,
        'brain_role' => 'owner',
        'brain_areas' => ['rules'],
    ])->assertSessionHasNoErrors();

    expect(app(BrainClientsService::class)->ownerOrganisationIdFor($lead))->toBeNull()
        ->and(app(ActivityLogQueryService::class)->organisationIdForOwner($lead))->toBeNull()
        ->and(app(BrainClientsService::class)->ownerOrganisationIdFor($this->admin))->toBe($this->organisation->id);

    $sources = $this->actingAs($lead)->get(route('repositories.index'))->assertOk();
    $sources->assertInertia(fn ($page) => $page->where('sources.0.last_sync_report', null));
});

it('adds a person\'s own access to the group limit, and removing them from the group takes both away', function (): void {
    app(GrantOrganisationAccessAction::class)->execute($this->developer, $this->organisation, BrainRoleEnum::Guest, VisibilityEnum::Internal);
    $key = $this->developerKey['key']->fresh('principal');

    // The own grant has no limit: together they read every area, with the group's member role.
    $union = app(PerimeterResolverService::class)->fromApiKey($key);
    expect($union->areas)->toBe([])
        ->and($union->role)->toBe(BrainRoleEnum::Member);

    Grant::query()->whereNull('user_group_id')->where('principal_id', $this->developerPrincipal->id)->delete();
    $group = UserGroup::query()->where('name', 'Developers')->sole();
    $this->actingAs($this->admin)->put(route('groups.update', $group), ['name' => 'Developers', 'user_ids' => []])->assertRedirect();

    expect(Grant::query()->where('principal_id', $this->developerPrincipal->id)->exists())->toBeFalse();
    $this->getJson('/api/v1/items?scope='.$this->organisation->id, $this->apiHeaders)->assertUnauthorized();
});

it('narrows a key to the areas asked for and refuses one reaching past its grant', function (): void {
    $narrowed = app(CreateApiKeyAction::class)->execute(
        principal: $this->developerPrincipal,
        name: 'decisions only',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $this->organisation->id,
        role: BrainRoleEnum::Member,
        maxVisibility: VisibilityEnum::Internal,
        areas: ['decisions'],
    );
    expect(app(PerimeterResolverService::class)->fromApiKey($narrowed['key']->fresh('principal'))->areas)->toBe(['decisions']);

    expect(fn () => app(CreateApiKeyAction::class)->execute(
        principal: $this->developerPrincipal,
        name: 'style',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $this->organisation->id,
        role: BrainRoleEnum::Member,
        maxVisibility: VisibilityEnum::Internal,
        areas: ['style'],
    ))->toThrow(InvalidArgumentException::class, 'API key areas or paths are outside the principal grant.');
});

it('creates an agent key from the keys page limited to the chosen area and folder', function (): void {
    $this->actingAs($this->admin)->post(route('api-keys.store'), [
        'name' => 'Rules agent',
        'principal' => 'agent',
        'agent_name' => 'Rules bot',
        'role' => 'member',
        'max_visibility' => 'internal',
        'areas' => ['rules'],
        'path_prefix' => '/rules/',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $key = ApiKey::query()->where('name', 'Rules agent')->sole();
    $agentGrant = Grant::query()->where('principal_id', $key->principal_id)->sole();
    $perimeter = app(PerimeterResolverService::class)->fromApiKey($key->fresh('principal'));

    expect($agentGrant->areas)->toBe(['rules'])
        ->and($agentGrant->path_prefixes)->toBe(['rules'])
        ->and($perimeter->areas)->toBe(['rules'])
        ->and($perimeter->pathPrefixes)->toBe(['rules']);

    $this->actingAs($this->admin)->post(route('api-keys.store'), [
        'name' => 'Bad', 'principal' => 'me', 'role' => 'member', 'max_visibility' => 'internal', 'areas' => ['nowhere'], 'path_prefix' => '../etc',
    ])->assertSessionHasErrors(['areas.0', 'path_prefix']);
});

it('matches a folder by prefix, never by LIKE wildcards', function (): void {
    $perimeter = app(PerimeterResolverService::class)->fromApiKey($this->developerKey['key']->fresh('principal'));
    $underscoreFolder = new PerimeterData(
        principalId: $perimeter->principalId,
        principalType: $perimeter->principalType,
        scopeType: $perimeter->scopeType,
        scopeId: $perimeter->scopeId,
        role: $perimeter->role,
        maxVisibility: $perimeter->maxVisibility,
        pathPrefixes: ['rule_'],
    );

    // `rule_/%` as a LIKE pattern would match rules/handbook.md.
    expect(Item::query()->withinPerimeter($underscoreFolder)->count())->toBe(0)
        ->and($underscoreFolder->allowsPath('rules/handbook.md'))->toBeFalse();
});

it('stores grant area and path limits as lists', function (): void {
    $grant = Grant::factory()->create([
        'principal_id' => $this->developerPrincipal->id,
        'scope_type' => 'organisation',
        'scope_id' => $this->organisation->id,
        'areas' => ['rules'],
        'path_prefixes' => ['rules/engineering'],
    ]);

    expect($grant->fresh()->areas)->toBe(['rules'])
        ->and($grant->fresh()->path_prefixes)->toBe(['rules/engineering']);
});
