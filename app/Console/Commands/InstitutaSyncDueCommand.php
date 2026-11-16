<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncSourceJob;
use App\Services\SourceSyncScheduleService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Queues a sync for every source whose interval has passed. The scheduler runs it every
 * minute (`routes/console.php`); a source still at the indexed commit costs only a fetch.
 */
#[AsCommand(name: 'instituta:sync-due')]
class InstitutaSyncDueCommand extends Command
{
    protected $signature = 'instituta:sync-due';

    protected $description = 'Queue syncs for sources whose sync interval has passed';

    public function handle(SourceSyncScheduleService $schedule): int
    {
        $dueSources = $schedule->dueSources(now());

        foreach ($dueSources as $source) {
            SyncSourceJob::dispatch($source->id, isForced: false);
        }

        $this->info(sprintf('Queued %d due source sync(s).', $dueSources->count()));

        return self::SUCCESS;
    }
}
