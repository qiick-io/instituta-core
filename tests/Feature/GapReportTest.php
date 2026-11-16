<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyAbilityEnum;
use App\Enums\GapReportStatusEnum;
use App\Enums\SourceProviderEnum;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Tools\Http\InstitutaReportGapTool;
use App\Models\GapReport;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\GapReportedNotification;
use App\Services\PerimeterResolverService;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Agents report gaps and contradictions; owners turn them into proposals or dismiss them (#229).
 */
beforeEach(function (): void {
    Queue::fake();
    Notification::fake();
    $this->withoutVite();
    config(['instituta.mcp.enabled' => true]);

    $this->brainPath = storage_path('framework/testing/gaps-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $this->brainPath);

    $otherOrganisation = Organisation::factory()->create();
    $otherSource = app(SourceRegistryService::class)->add($otherOrganisation, 'Other', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($otherSource->fresh());
    $this->otherTenantItemId = Item::query()->where('stable_key', 'rules/coding')->sole()->id;

    $this->organisation = Organisation::factory()->create();
    $source = app(SourceRegistryService::class)->add($this->organisation, 'Fixture', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($source->fresh());

    $this->owner = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($this->owner, $this->organisation, RoleEnum::Owner);

    $agent = Principal::factory()->create(['organisation_id' => $this->organisation->id, 'type' => PrincipalTypeEnum::Agent, 'name' => 'Docs agent']);
    Grant::factory()->create(['principal_id' => $agent->id, 'scope_type' => ScopeTypeEnum::Organisation, 'scope_id' => $this->organisation->id, 'role' => RoleEnum::Guest, 'max_visibility' => VisibilityEnum::Internal]);
    $created = app(CreateApiKeyAction::class)->execute(
        principal: $agent,
        name: 'reader',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $this->organisation->id,
        role: RoleEnum::Guest,
        maxVisibility: VisibilityEnum::Internal,
        abilities: [ApiKeyAbilityEnum::BrainRead->value],
    );
    $this->headers = ['Authorization' => 'Bearer '.$created['plain']];
    $this->key = $created['key'];
});

afterEach(function (): void {
    File::deleteDirectory($this->brainPath);
});

function reportGap(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/gaps', [
        'kind' => 'contradiction',
        'summary' => 'Coding rules and the one-repo decision disagree on PR size',
        'question' => 'How big may a PR be?',
        'related' => [['item' => 'rules/coding', 'anchor' => 'coding-rules/style'], ['item' => 'decisions/one-repo']],
        ...$overrides,
    ], test()->headers);
}

it('lets a read-only agent key report a gap and notifies the organisation', function (): void {
    $gapId = reportGap()->assertCreated()->assertJsonPath('status', 'open')->json('id');

    $gapReport = GapReport::query()->findOrFail($gapId);

    expect($gapReport->related)->toHaveCount(2)
        ->and($gapReport->related[0])->toMatchArray(['stable_key' => 'rules/coding', 'anchor' => 'coding-rules/style'])
        ->and($gapReport->reporter?->name)->toBe('Docs agent');

    Notification::assertSentTo($this->owner, GapReportedNotification::class);
});

it('refuses a related item outside the perimeter and an invalid kind', function (): void {
    reportGap(['related' => [['item' => $this->otherTenantItemId]]])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'not_found');

    reportGap(['related' => [['item' => 'rules/coding', 'anchor' => 'nope']]])->assertUnprocessable();
    reportGap(['kind' => 'rant'])->assertUnprocessable()->assertJsonPath('code', 'validation_failed');

    expect(GapReport::query()->count())->toBe(0);
});

it('shows reports to owners only and never another tenant\'s', function (): void {
    reportGap()->assertCreated();

    $otherOwner = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($otherOwner, Organisation::factory()->create(), RoleEnum::Owner);
    $member = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($member, $this->organisation, RoleEnum::Member);

    $this->actingAs($this->owner)->get(route('brain.gaps.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/gaps')
            ->where('isOwner', true)
            ->has('gaps', 1)
            ->where('gaps.0.kind', 'contradiction')
            ->where('gaps.0.reporter', 'Docs agent'));

    $this->actingAs($member)->get(route('brain.gaps.index'))
        ->assertInertia(fn (Assert $page) => $page->where('isOwner', false)->has('gaps', 0));

    $this->actingAs($otherOwner)->get(route('brain.gaps.index'))
        ->assertInertia(fn (Assert $page) => $page->has('gaps', 0));
});

it('lets an owner dismiss a report, but not a member', function (): void {
    $gapId = reportGap()->json('id');
    $member = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($member, $this->organisation, RoleEnum::Member);

    $this->actingAs($member)->delete(route('brain.gaps.destroy', $gapId))->assertForbidden();

    $this->actingAs($this->owner)->delete(route('brain.gaps.destroy', $gapId))->assertRedirect(route('brain.gaps.index'));
    expect(GapReport::query()->findOrFail($gapId)->status)->toBe(GapReportStatusEnum::Dismissed);

    $this->actingAs($this->owner)->delete(route('brain.gaps.destroy', $gapId))->assertSessionHasErrors('gap');

    $this->actingAs($this->owner)->get(route('brain.gaps.index', ['status' => 'dismissed']))
        ->assertInertia(fn (Assert $page) => $page->where('statusFilter', 'dismissed')->has('gaps', 1));
});

it('converts a report when the owner opens the proposal that answers it', function (): void {
    $gapId = reportGap()->json('id');

    $this->actingAs($this->owner)->post(route('brain.proposals.store'), [
        'action' => 'create',
        'title' => 'PR size rule',
        'stable_key' => 'rules/pr-size',
        'path' => 'rules/pr-size.md',
        'area' => 'rules',
        'body' => "# PR size\n\nKeep PRs under 400 lines.",
        'gap_report_id' => $gapId,
    ])->assertRedirect();

    $gapReport = GapReport::query()->findOrFail($gapId);

    expect($gapReport->status)->toBe(GapReportStatusEnum::Converted)
        ->and($gapReport->proposal_id)->toBe(Proposal::query()->where('stable_key', 'rules/pr-size')->sole()->id)
        ->and($gapReport->decided_by_user_id)->toBe($this->owner->id);
});

it('reports a gap over MCP', function (): void {
    request()->attributes->set('instituta.api_key', $this->key->fresh('principal'));
    request()->attributes->set('instituta.perimeter', app(PerimeterResolverService::class)->fromApiKey($this->key->fresh('principal')));

    HttpBrainServer::tool(InstitutaReportGapTool::class, ['kind' => 'missing', 'summary' => 'No rule on commit messages', 'related' => [['item' => 'rules/coding']]])
        ->assertOk()
        ->assertSee('open');

    HttpBrainServer::tool(InstitutaReportGapTool::class, ['kind' => 'missing', 'summary' => 'x', 'related' => [['item' => $this->otherTenantItemId]]])
        ->assertHasErrors(['[not_found]']);
    HttpBrainServer::tool(InstitutaReportGapTool::class, ['kind' => 'rant', 'summary' => 'x'])
        ->assertHasErrors(['[validation_failed]']);
    HttpBrainServer::tool(InstitutaReportGapTool::class, ['kind' => 'missing', 'summary' => 'x', 'related' => ['not an object']])
        ->assertHasErrors(['[validation_failed]']);

    expect(GapReport::query()->where('kind', 'missing')->count())->toBe(1);
});
