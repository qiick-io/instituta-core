<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ProposalStatusEnum;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Proposal;
use App\Models\User;
use App\Services\Dashboard\DashboardOverviewMetricsService;
use App\Services\PerimeterResolverService;

it('returns open proposal count and caps recent proposals for the dashboard', function (): void {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create();
    $principal = Principal::factory()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $user->id,
        'type' => 'person',
        'name' => $user->name,
    ]);
    $grant = Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisation->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    Proposal::factory()->count(7)->create([
        'organisation_id' => $organisation->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisation->id,
        'proposed_by_principal_id' => $principal->id,
        'status' => ProposalStatusEnum::Open,
    ]);

    $perimeter = app(PerimeterResolverService::class)->fromGrant($principal, $grant);
    $metrics = app(DashboardOverviewMetricsService::class)->openProposals($perimeter);

    expect($metrics['open_count'])->toBe(7)
        ->and($metrics['recent'])->toHaveCount(5);
});
