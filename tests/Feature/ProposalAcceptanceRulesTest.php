<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyAbilityEnum;
use App\Enums\ProposalStatusEnum;
use App\Jobs\MaterialiseProposalJob;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Tools\Http\InstitutaUpdateProposalTool;
use App\Models\ApiKey;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Proposal;
use App\Models\User;
use App\Services\PerimeterResolverService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Accepting a write proposal is a human decision (#197).
 */
beforeEach(function (): void {
    Queue::fake();
    config([
        'instituta.api.write_enabled' => true,
        'instituta.mcp.write_enabled' => true,
        'instituta.mcp.enabled' => true,
    ]);
    $this->organisation = Organisation::factory()->create();
});

/**
 * An owner-grant principal of the given type with a brain:write key.
 *
 * @return array{principal: Principal, key: ApiKey, plain: string}
 */
function acceptanceKey(Organisation $organisation, PrincipalTypeEnum $type): array
{
    $principal = Principal::factory()->create([
        'organisation_id' => $organisation->id,
        'type' => $type,
        'user_id' => $type === PrincipalTypeEnum::Person ? User::factory() : null,
    ]);

    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisation->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    $created = app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: $type->value.' key',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
        abilities: [ApiKeyAbilityEnum::BrainRead->value, ApiKeyAbilityEnum::BrainWrite->value],
    );

    return ['principal' => $principal, 'key' => $created['key'], 'plain' => $created['plain']];
}

/**
 * Opens a proposal over REST with the given key and returns its id.
 */
function openProposalWith(string $plainKey): string
{
    return test()->postJson('/api/v1/proposals', [
        'action' => 'create',
        'stable_key' => 'rules/naming',
        'title' => 'Naming rule',
        'path' => 'rules/naming.md',
        'body' => '# Naming',
    ], ['Authorization' => 'Bearer '.$plainKey])->assertCreated()->json('id');
}

function decideOverRest(string $proposalId, string $plainKey, string $status): TestResponse
{
    return test()->patchJson('/api/v1/proposals/'.$proposalId, ['status' => $status], ['Authorization' => 'Bearer '.$plainKey]);
}

it('refuses a key accepting the proposal it opened, over REST and MCP', function (): void {
    $agent = acceptanceKey($this->organisation, PrincipalTypeEnum::Agent);
    config(['instituta.proposals.agents_can_accept' => true]);
    $proposalId = openProposalWith($agent['plain']);

    decideOverRest($proposalId, $agent['plain'], 'accepted')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A proposal cannot be accepted by the key or principal that opened it.')
        ->assertJsonPath('code', 'self_accept_refused');

    request()->attributes->set('instituta.api_key', $agent['key']->fresh('principal'));
    request()->attributes->set('instituta.perimeter', app(PerimeterResolverService::class)->fromApiKey($agent['key']->fresh('principal')));

    HttpBrainServer::tool(InstitutaUpdateProposalTool::class, ['id' => $proposalId, 'status' => 'accepted'])
        ->assertHasErrors(['[self_accept_refused] A proposal cannot be accepted by the key or principal that opened it.']);

    expect(Proposal::query()->findOrFail($proposalId)->status)->toBe(ProposalStatusEnum::Open);
    Queue::assertNotPushed(MaterialiseProposalJob::class);
});

it('refuses another key of the same principal accepting its proposal', function (): void {
    $person = acceptanceKey($this->organisation, PrincipalTypeEnum::Person);
    $proposalId = openProposalWith($person['plain']);
    $secondKey = app(CreateApiKeyAction::class)->execute(
        principal: $person['principal'],
        name: 'second',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $this->organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
        abilities: [ApiKeyAbilityEnum::BrainRead->value, ApiKeyAbilityEnum::BrainWrite->value],
    );

    decideOverRest($proposalId, $secondKey['plain'], 'accepted')->assertUnprocessable();
});

it('refuses agent and service keys accepting by default but lets them reject', function (PrincipalTypeEnum $type): void {
    $author = acceptanceKey($this->organisation, PrincipalTypeEnum::Person);
    $decider = acceptanceKey($this->organisation, $type);

    $firstId = openProposalWith($author['plain']);
    decideOverRest($firstId, $decider['plain'], 'accepted')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Agent and service keys cannot accept proposals; a person must accept them.');

    decideOverRest($firstId, $decider['plain'], 'rejected')
        ->assertOk()
        ->assertJsonPath('status', 'rejected');
})->with([PrincipalTypeEnum::Agent, PrincipalTypeEnum::Service]);

it('lets an agent key accept another principal\'s proposal when the install allows it', function (): void {
    config(['instituta.proposals.agents_can_accept' => true]);
    $author = acceptanceKey($this->organisation, PrincipalTypeEnum::Person);
    $agent = acceptanceKey($this->organisation, PrincipalTypeEnum::Agent);
    $proposalId = openProposalWith($author['plain']);

    decideOverRest($proposalId, $agent['plain'], 'accepted')->assertOk();

    Queue::assertPushed(MaterialiseProposalJob::class);
});

it('lets a person key accept an agent\'s proposal', function (): void {
    $agent = acceptanceKey($this->organisation, PrincipalTypeEnum::Agent);
    $person = acceptanceKey($this->organisation, PrincipalTypeEnum::Person);
    $proposalId = openProposalWith($agent['plain']);

    decideOverRest($proposalId, $person['plain'], 'accepted')
        ->assertOk()
        ->assertJsonPath('status', 'accepted');
});

it('lets a key withdraw its own proposal by rejecting it', function (): void {
    $agent = acceptanceKey($this->organisation, PrincipalTypeEnum::Agent);
    $proposalId = openProposalWith($agent['plain']);

    decideOverRest($proposalId, $agent['plain'], 'rejected')->assertOk();
});

it('lets a signed-in person accept in the web ui a proposal their own agent opened', function (): void {
    $agent = acceptanceKey($this->organisation, PrincipalTypeEnum::Agent);
    $person = acceptanceKey($this->organisation, PrincipalTypeEnum::Person);
    $proposalId = openProposalWith($agent['plain']);

    $this->actingAs($person['principal']->user)
        ->patch(route('brain.proposals.update', $proposalId), ['status' => 'accepted'])
        ->assertRedirect(route('brain.proposals.show', $proposalId));

    expect(Proposal::query()->findOrFail($proposalId)->status)->toBe(ProposalStatusEnum::Accepted);
});
