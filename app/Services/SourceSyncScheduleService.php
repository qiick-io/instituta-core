<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SyncStatusEnum;
use App\Models\Source;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Decides which sources the scheduler re-syncs (#199): those with a sync interval whose last
 * sync (successful or failed) is older than that interval, and that are not syncing now.
 */
final class SourceSyncScheduleService
{
    /**
     * @return Collection<int, Source>
     */
    public function dueSources(CarbonInterface $now): Collection
    {
        return Source::query()
            ->whereNotNull('sync_interval_minutes')
            ->where('sync_status', '!=', SyncStatusEnum::Syncing)
            ->orderBy('last_synced_at')
            ->get()
            ->filter(fn (Source $source): bool => $source->last_synced_at === null
                || $source->last_synced_at->lessThanOrEqualTo($now->copy()->subMinutes((int) $source->sync_interval_minutes)))
            ->values();
    }
}
