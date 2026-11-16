<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\ScopeTypeEnum;
use App\Models\Company;
use App\Models\Product;

/**
 * The organisation / company / product columns of a perimeter's scope, for rows that store
 * all three (proposals, audit calls, sources). One place, so every writer agrees.
 */
final class PerimeterScopeColumnsService
{
    /**
     * @return array{0: string|null, 1: string|null, 2: string|null} organisation, company, product ids
     */
    public function columnsFor(PerimeterData $perimeter): array
    {
        return match ($perimeter->scopeType) {
            ScopeTypeEnum::Organisation => [$perimeter->scopeId, null, null],
            ScopeTypeEnum::Company => [
                Company::query()->whereKey($perimeter->scopeId)->value('organisation_id'),
                $perimeter->scopeId,
                null,
            ],
            ScopeTypeEnum::Product => $this->productColumns($perimeter->scopeId),
        };
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function productColumns(string $productId): array
    {
        $product = Product::query()->with('company')->find($productId);

        return [
            $product?->company?->organisation_id,
            $product?->company_id,
            $productId,
        ];
    }
}
