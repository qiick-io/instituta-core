<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\ScopeTypeEnum;
use App\Enums\QuotaMetricEnum;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\ContractCall;
use App\Models\Product;

/**
 * Appends contract_calls audit rows (never content or secrets).
 */
final class ContractAuditService
{
    public function __construct(
        private readonly QuotaEnforcementService $quotas = new QuotaEnforcementService,
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
        [$organisationId, $companyId, $productId] = $this->scopeColumns($perimeter);

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

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function scopeColumns(PerimeterData $perimeter): array
    {
        return match ($perimeter->scopeType) {
            ScopeTypeEnum::Organisation => [$perimeter->scopeId, null, null],
            ScopeTypeEnum::Company => [
                Company::query()->whereKey($perimeter->scopeId)->value('organisation_id'),
                $perimeter->scopeId,
                null,
            ],
            ScopeTypeEnum::Product => $this->productScopeColumns($perimeter->scopeId),
        };
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function productScopeColumns(string $productId): array
    {
        $product = Product::query()->with('company')->find($productId);

        return [
            $product?->company?->organisation_id,
            $product?->company_id,
            $productId,
        ];
    }
}
