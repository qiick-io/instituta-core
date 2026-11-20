<?php

declare(strict_types=1);

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\QuotaMetricEnum;
use App\Exceptions\QuotaExceededException;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Quota;
use App\Models\QuotaUsage;
use App\Services\ContractAuditService;
use App\Services\QuotaEnforcementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

it('enforces organisation contract_calls quota and isolates usage rows', function (): void {
    $orgA = Organisation::factory()->create();
    $orgB = Organisation::factory()->create();
    $principalA = Principal::factory()->create(['organisation_id' => $orgA->id]);
    $principalB = Principal::factory()->create(['organisation_id' => $orgB->id]);

    app(QuotaEnforcementService::class)->setLimit(
        ScopeTypeEnum::Organisation,
        $orgA->id,
        QuotaMetricEnum::ContractCalls,
        2,
    );

    $audit = app(ContractAuditService::class);
    $perimeterA = new PerimeterData(
        principalId: $principalA->id,
        principalType: PrincipalTypeEnum::Person,
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $orgA->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );
    $perimeterB = new PerimeterData(
        principalId: $principalB->id,
        principalType: PrincipalTypeEnum::Person,
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $orgB->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );

    $audit->record('instituta_scopes', $perimeterA);
    $audit->record('instituta_scopes', $perimeterA);

    expect(fn () => $audit->record('instituta_scopes', $perimeterA))
        ->toThrow(QuotaExceededException::class);

    $audit->record('instituta_scopes', $perimeterB);

    $quotaA = Quota::query()
        ->where('scope_id', $orgA->id)
        ->where('metric', QuotaMetricEnum::ContractCalls->value)
        ->firstOrFail();
    $usageA = QuotaUsage::query()->where('quota_id', $quotaA->id)->firstOrFail();

    expect($usageA->used)->toBe(2)
        ->and(Quota::query()->where('scope_id', $orgB->id)->exists())->toBeFalse();
});

it('sets quotas via artisan and enforces source_syncs metric', function (): void {
    $org = Organisation::factory()->create();

    $this->artisan('instituta:quota:set', [
        'scope_type' => 'organisation',
        'scope_id' => $org->id,
        'metric' => 'source_syncs',
        'limit' => 1,
    ])->assertSuccessful();

    $quotas = app(QuotaEnforcementService::class);
    $quotas->consume($org->id, QuotaMetricEnum::SourceSyncs);

    expect(fn () => $quotas->consume($org->id, QuotaMetricEnum::SourceSyncs))
        ->toThrow(QuotaExceededException::class);

    $exception = null;

    try {
        $quotas->consume($org->id, QuotaMetricEnum::SourceSyncs);
    } catch (QuotaExceededException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(QuotaExceededException::class)
        ->and($exception->metric)->toBe(QuotaMetricEnum::SourceSyncs)
        ->and($exception->limitValue)->toBe(1)
        ->and($exception->used)->toBe(1);
});

it('returns 429 json for quota exceeded on api requests', function (): void {
    $exception = new QuotaExceededException(
        metric: QuotaMetricEnum::ContractCalls,
        limitValue: 5,
        used: 5,
    );

    $request = Request::create('/api/v1/scopes', 'GET');
    $request->headers->set('Accept', 'application/json');

    $response = $exception->render($request);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(429)
        ->and($response->getData(true)['error'])->toBe('quota_exceeded');
});
