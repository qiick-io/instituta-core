<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ProposalStatusEnum;
use App\Jobs\MaterialiseProposalJob;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, org: Organisation, principal: Principal}
 */
function proposalsUiSeed(RoleEnum $role = RoleEnum::Owner): array
{
    $user = User::factory()->create();
    $org = Organisation::factory()->create(['name' => 'Proposals Org']);
    $principal = Principal::factory()->create([
        'organisation_id' => $org->id,
        'user_id' => $user->id,
        'type' => 'person',
        'name' => $user->name,
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => $role,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    return compact('user', 'org', 'principal');
}

it('lists open proposals in the session perimeter', function (): void {
    $seed = proposalsUiSeed();
    Proposal::factory()->create([
        'organisation_id' => $seed['org']->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $seed['org']->id,
        'proposed_by_principal_id' => $seed['principal']->id,
        'title' => 'Open proposal',
        'status' => ProposalStatusEnum::Open,
    ]);
    Proposal::factory()->accepted()->create([
        'organisation_id' => $seed['org']->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $seed['org']->id,
        'proposed_by_principal_id' => $seed['principal']->id,
        'title' => 'Already accepted',
    ]);

    $this->actingAs($seed['user'])
        ->get(route('review.proposals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/proposals')
            ->where('hasPerimeter', true)
            ->where('canDecide', true)
            ->has('proposals', 1)
            ->where('proposals.0.title', 'Open proposal')
            ->missing('proposals.0.body'));
});

it('shows proposal body on the detail page for owners', function (): void {
    $seed = proposalsUiSeed();
    $proposal = Proposal::factory()->create([
        'organisation_id' => $seed['org']->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $seed['org']->id,
        'proposed_by_principal_id' => $seed['principal']->id,
        'title' => 'Detail me',
        'body' => "# Secret for humans only\n",
    ]);

    $this->actingAs($seed['user'])
        ->get(route('review.proposals.show', $proposal->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/proposals/show')
            ->where('canDecide', true)
            ->where('proposal.title', 'Detail me')
            ->where('proposal.body', "# Secret for humans only\n"));
});

it('lets an owner accept a proposal and queues materialisation', function (): void {
    Queue::fake();
    $seed = proposalsUiSeed();
    $proposal = Proposal::factory()->create([
        'organisation_id' => $seed['org']->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $seed['org']->id,
        'proposed_by_principal_id' => $seed['principal']->id,
    ]);

    $this->actingAs($seed['user'])
        ->patch(route('review.proposals.update', $proposal->id), [
            'status' => 'accepted',
        ])
        ->assertRedirect(route('review.proposals.show', $proposal->id));

    expect($proposal->fresh()->status)->toBe(ProposalStatusEnum::Accepted);
    Queue::assertPushed(MaterialiseProposalJob::class);
});

it('refuses guests from accepting proposals', function (): void {
    Queue::fake();
    $seed = proposalsUiSeed(RoleEnum::Guest);
    $proposal = Proposal::factory()->create([
        'organisation_id' => $seed['org']->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $seed['org']->id,
        'proposed_by_principal_id' => $seed['principal']->id,
    ]);

    $this->actingAs($seed['user'])
        ->from(route('review.proposals.show', $proposal->id))
        ->patch(route('review.proposals.update', $proposal->id), [
            'status' => 'accepted',
        ])
        ->assertRedirect(route('review.proposals.show', $proposal->id))
        ->assertSessionHasErrors('status');

    expect($proposal->fresh()->status)->toBe(ProposalStatusEnum::Open);
    Queue::assertNothingPushed();
});

it('requires authentication for proposals', function (): void {
    $this->get(route('review.proposals.index'))->assertRedirect();
});

it('caps the proposals screen at 100 proposals', function (): void {
    $seed = proposalsUiSeed();
    Proposal::factory()->count(105)->create([
        'organisation_id' => $seed['org']->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $seed['org']->id,
        'proposed_by_principal_id' => $seed['principal']->id,
        'status' => ProposalStatusEnum::Open,
    ]);

    $this->actingAs($seed['user'])
        ->get(route('review.proposals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/proposals')
            ->has('proposals', 100));
});
