<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\ContractCall;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @return array{org: Organisation, user: User, principal: Principal}
 */
function sessionReplayOwnerContext(): array
{
    $org = Organisation::factory()->create();
    $user = User::factory()->create();
    $principal = Principal::factory()->create([
        'organisation_id' => $org->id,
        'user_id' => $user->id,
        'type' => 'person',
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    return compact('org', 'user', 'principal');
}

it('scopes session replay to the owner organisation and hides other tenants', function (): void {
    ['org' => $orgA, 'user' => $ownerA, 'principal' => $principalA] = sessionReplayOwnerContext();
    ['org' => $orgB, 'user' => $ownerB, 'principal' => $principalB] = sessionReplayOwnerContext();

    $callA = ContractCall::factory()->create([
        'organisation_id' => $orgA->id,
        'principal_id' => $principalA->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $orgA->id,
        'tool_or_endpoint' => 'brain.search',
        'item_ids' => ['item-a'],
        'token_estimate' => 42,
    ]);

    ContractCall::factory()->create([
        'organisation_id' => $orgB->id,
        'principal_id' => $principalB->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $orgB->id,
        'tool_or_endpoint' => 'brain.get',
        'item_ids' => ['item-b'],
    ]);

    $this->actingAs($ownerA)
        ->get(route('brain.session-replay'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('brain/session-replay')
            ->where('is_owner', true)
            ->has('calls.data', 1)
            ->where('calls.data.0.id', $callA->id)
            ->where('calls.data.0.tool_or_endpoint', 'brain.search')
            ->where('calls.data.0.item_ids', ['item-a'])
            ->where('calls.data.0.token_estimate', 42)
            ->missing('calls.data.0.request_body')
            ->missing('calls.data.0.response_body')
            ->missing('calls.data.0.key_hash')
            ->missing('calls.data.0.secret'));

    $member = User::factory()->create();
    $memberPrincipal = Principal::factory()->create([
        'organisation_id' => $orgA->id,
        'user_id' => $member->id,
        'type' => 'person',
    ]);
    Grant::factory()->create([
        'principal_id' => $memberPrincipal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $orgA->id,
        'role' => RoleEnum::Member,
        'max_visibility' => VisibilityEnum::Internal,
    ]);

    $this->actingAs($member)
        ->get(route('brain.session-replay'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('brain/session-replay')
            ->where('is_owner', false)
            ->where('calls.data', []));
});

it('filters session replay by principal and tool', function (): void {
    ['org' => $org, 'user' => $user, 'principal' => $principal] = sessionReplayOwnerContext();
    $otherPrincipal = Principal::factory()->create([
        'organisation_id' => $org->id,
        'type' => 'person',
    ]);

    ContractCall::factory()->create([
        'organisation_id' => $org->id,
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'tool_or_endpoint' => 'brain.search',
    ]);

    ContractCall::factory()->create([
        'organisation_id' => $org->id,
        'principal_id' => $otherPrincipal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'tool_or_endpoint' => 'brain.get',
    ]);

    $this->actingAs($user)
        ->get(route('brain.session-replay', [
            'principal_id' => $principal->id,
            'tool_or_endpoint' => 'brain.search',
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('brain/session-replay')
            ->has('calls.data', 1)
            ->where('calls.data.0.tool_or_endpoint', 'brain.search')
            ->where('calls.data.0.principal.id', $principal->id));
});
