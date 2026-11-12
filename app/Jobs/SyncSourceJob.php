<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Source;
use App\Services\SourceSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncSourceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $sourceId,
    ) {}

    public function handle(SourceSyncService $sync): void
    {
        $source = Source::query()->find($this->sourceId);
        if ($source === null) {
            return;
        }

        $sync->sync($source);
    }
}
