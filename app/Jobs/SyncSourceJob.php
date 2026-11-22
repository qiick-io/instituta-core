<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Middleware\SetPermissionsTeamJobMiddleware;
use App\Models\Source;
use App\Services\SourceSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Syncs one source in the background. Unique per source, so a burst of push webhooks or a
 * slow sync overlapping the scheduler queues one run, not many.
 *
 * `$isForced = false` (scheduler, webhook) stops after the fetch when the commit is unchanged.
 */
final class SyncSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Seconds the uniqueness lock lives if a run dies without releasing it. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $sourceId,
        public readonly bool $isForced = true,
    ) {}

    public function uniqueId(): string
    {
        return $this->sourceId;
    }

    /**
     * @return list<class-string>
     */
    public function middleware(): array
    {
        return [SetPermissionsTeamJobMiddleware::class];
    }

    public function organisationIdForPermissions(): ?string
    {
        $organisationId = Source::query()->whereKey($this->sourceId)->value('organisation_id');

        return is_string($organisationId) && $organisationId !== '' ? $organisationId : null;
    }

    public function handle(SourceSyncService $sync): void
    {
        $source = Source::query()->find($this->sourceId);
        if ($source === null) {
            return;
        }

        $sync->sync($source, $this->isForced);
    }
}
