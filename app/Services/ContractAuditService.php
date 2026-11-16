<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Enums\QuotaMetricEnum;
use App\Models\ApiKey;
use App\Models\ContractCall;

/**
 * Appends contract_calls audit rows (never content or secrets).
 */
final class ContractAuditService
{
    public function __construct(
        private readonly QuotaEnforcementService $quotas = new QuotaEnforcementService,
        private readonly PerimeterScopeColumnsService $scopeColumns = new PerimeterScopeColumnsService,
    ) {}

    /**
     * @param  list<string>|null  $itemIds
     */
    public function record(
        string $toolOrEndpoint,
        PerimeterData $perimeter,
        ?ApiKey $apiKey = null,
        ?array $itemIds = null,
        ?int $tokenEstimate = null,
    ): ContractCall {
        [$organisationId, $companyId, $productId] = $this->scopeColumns->columnsFor($perimeter);

        if (is_string($organisationId) && $organisationId !== '') {
            $this->quotas->consume(
                organisationId: $organisationId,
                metric: QuotaMetricEnum::ContractCalls,
                companyId: $companyId,
            );
        }

        return ContractCall::query()->create([
            'principal_id' => $perimeter->principalId,
            'api_key_id' => $apiKey?->id,
            'agent_session_id' => null,
            'tool_or_endpoint' => $toolOrEndpoint,
            'scope_type' => $perimeter->scopeType,
            'scope_id' => $perimeter->scopeId,
            'organisation_id' => $organisationId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'item_ids' => $itemIds,
            'token_estimate' => $tokenEstimate,
            'called_at' => now(),
        ]);
    }
}
