<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Brain\Data\PerimeterData;
use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ProposalActionEnum;
use App\Enums\ProposalStatusEnum;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Proposal;
use App\Services\ProposalQueryService;

it('shares stable proposal fields between API summary and UI row', function (): void {
    $organisation = Organisation::factory()->create();
    $principal = Principal::factory()->create([
        'organisation_id' => $organisation->id,
        'type' => PrincipalTypeEnum::Person,
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisation->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    $proposal = Proposal::factory()->create([
        'organisation_id' => $organisation->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisation->id,
        'proposed_by_principal_id' => $principal->id,
        'status' => ProposalStatusEnum::Open,
        'action' => ProposalActionEnum::Update,
        'stable_key' => 'rules/coding',
        'title' => 'Coding rules',
        'path' => 'rules/coding.md',
        'area' => 'rules',
        'body' => "secret body\n",
    ]);

    $issued = app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: 'query-surfaces',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );

    $perimeter = new PerimeterData(
        principalId: $principal->id,
        principalType: PrincipalTypeEnum::Person,
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );

    $queries = app(ProposalQueryService::class);
    $apiList = $queries->list($perimeter, $issued['key'], ProposalStatusEnum::Open, 'api');
    $uiList = $queries->listForUi($perimeter, ProposalStatusEnum::Open);
    $uiDetail = $queries->findForUi($perimeter, $proposal->id);
    $apiSummary = $queries->toSummary($proposal);

    expect($apiList['proposals'])->toHaveCount(1)
        ->and($uiList)->toHaveCount(1)
        ->and($uiDetail)->not->toBeNull();

    $sharedKeys = ['id', 'status', 'action', 'stable_key', 'title', 'path', 'area', 'source_id', 'created_at', 'updated_at'];

    foreach ($sharedKeys as $key) {
        expect($apiSummary[$key])->toBe($uiList[0][$key])
            ->and($apiList['proposals'][0][$key])->toBe($uiDetail[$key]);
    }

    expect($apiSummary)->not->toHaveKey('body')
        ->and($uiDetail['body'])->toBe("secret body\n")
        ->and($uiList[0])->not->toHaveKey('body');
});
