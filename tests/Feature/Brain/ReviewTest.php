<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\RoleEnum;
use App\Enums\GapReportKindEnum;
use App\Enums\GapReportStatusEnum;
use App\Enums\ProposalStatusEnum;
use App\Models\GapReport;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Proposal;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Review gathers what waits for a person (#255): open proposals and, for owners, open gaps.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->organisation = Organisation::factory()->create();
    $reporter = Principal::factory()->create(['organisation_id' => $this->organisation->id]);

    foreach ([ProposalStatusEnum::Open, ProposalStatusEnum::Open, ProposalStatusEnum::Accepted] as $status) {
        Proposal::factory()->create([
            'organisation_id' => $this->organisation->id,
            'scope_type' => 'organisation',
            'scope_id' => $this->organisation->id,
            'status' => $status,
        ]);
    }

    foreach ([GapReportStatusEnum::Open, GapReportStatusEnum::Dismissed] as $status) {
        GapReport::query()->create([
            'organisation_id' => $this->organisation->id,
            'scope_type' => 'organisation',
            'scope_id' => $this->organisation->id,
            'kind' => GapReportKindEnum::Missing,
            'status' => $status,
            'summary' => 'A gap',
            'reported_by_principal_id' => $reporter->id,
        ]);
    }

    // Another organisation's open items never count.
    Proposal::factory()->create(['status' => ProposalStatusEnum::Open]);
});

function reviewer(Organisation $organisation, RoleEnum $role): User
{
    $user = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($user, $organisation, $role);

    return $user;
}

it('counts open proposals and, for owners, open gaps', function (): void {
    $this->actingAs(reviewer($this->organisation, RoleEnum::Owner))
        ->get(route('review.proposals.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review.open_proposals', 2)
            ->where('review.open_gaps', 1));

    $this->actingAs(reviewer($this->organisation, RoleEnum::Member))
        ->get(route('review.proposals.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review.open_proposals', 2)
            ->where('review.open_gaps', 0));
});

it('opens review on the proposals', function (): void {
    $this->actingAs(reviewer($this->organisation, RoleEnum::Owner))
        ->get('/review')
        ->assertRedirect('/review/proposals');
});
