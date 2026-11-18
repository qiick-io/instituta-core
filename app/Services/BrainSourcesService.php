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
                // The report covers the whole source (counts, skipped paths): not for a perimeter limited to some areas (#334).
                'last_sync_report' => $perimeter->isLimited() ? null : $source->last_sync_report,
                'scope_type' => $source->scope_type->value,
                'remote_url' => $source->remote_url,
                'local_path' => $source->local_path,
                'ref' => $source->ref,
                'path' => $source->path,
                'adapter' => $source->adapter->value,
                'default_visibility' => $source->default_visibility->value,
                'is_shared' => $source->is_shared,
                'sync_interval_minutes' => $source->sync_interval_minutes,
                'has_webhook' => $source->webhook_secret !== null,
            ];
        }

        return $rows;
    }
}
