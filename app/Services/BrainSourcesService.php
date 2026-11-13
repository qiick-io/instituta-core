<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\ScopeTypeEnum;
use App\Models\Source;

/**
 * Sources list for the human UI inside the session perimeter.
 */
final class BrainSourcesService
{
    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     provider: string,
     *     sync_status: string,
     *     last_synced_commit: string|null,
     *     last_synced_at: string|null,
     *     last_sync_report: array<string, mixed>|null,
     *     scope_type: string
     * }>
     */
    public function list(PerimeterData $perimeter): array
    {
        $query = Source::query()->orderBy('name');

        match ($perimeter->scopeType) {
            ScopeTypeEnum::Organisation => $query->where('organisation_id', $perimeter->scopeId),
            ScopeTypeEnum::Company => $query->where('company_id', $perimeter->scopeId),
            ScopeTypeEnum::Product => $query->where('product_id', $perimeter->scopeId),
        };

        /** @var list<Source> $sources */
        $sources = $query->get()->all();

        $rows = [];
        foreach ($sources as $source) {
            $rows[] = [
                'id' => $source->id,
                'name' => $source->name,
                'provider' => $source->provider->value,
                'sync_status' => $source->sync_status->value,
                'last_synced_commit' => $source->last_synced_commit,
                'last_synced_at' => $source->last_synced_at?->toIso8601String(),
                'last_sync_report' => $source->last_sync_report,
                'scope_type' => $source->scope_type->value,
            ];
        }

        return $rows;
    }
}
