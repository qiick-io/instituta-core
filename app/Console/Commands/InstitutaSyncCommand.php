<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncSourceJob;
use App\Models\Source;
use App\Services\SourceSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class InstitutaSyncCommand extends Command
{
    protected $signature = 'instituta:sync
        {source? : Source UUID (omit to sync every source)}
        {--queue : Dispatch SyncSourceJob instead of running inline}';

    protected $description = 'Read-only sync of registered sources into the index';

    public function handle(SourceSyncService $sync): int
    {
        $sources = $this->resolveSources();
        if ($sources->isEmpty()) {
            $this->warn('No sources to sync.');

            return self::SUCCESS;
        }

        $queue = (bool) $this->option('queue');
        $failed = 0;

        foreach ($sources as $source) {
            if ($queue) {
                SyncSourceJob::dispatch($source->id);
                $this->line('Queued sync for '.$source->id.' ('.$source->name.')');

                continue;
            }

            $report = $sync->sync($source);
            $source->refresh();
            $status = $source->sync_status->value;
            if ($status === 'failed') {
                $failed++;
                $this->error('Failed '.$source->id.': '.($report['message'] ?? $report['reason'] ?? 'unknown'));

                continue;
            }

            $this->info(sprintf(
                'Synced %s (%s): %d items, commit %s',
                $source->id,
                $source->name,
                (int) ($report['items_upserted'] ?? 0),
                (string) ($report['commit'] ?? 'n/a'),
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Source>
     */
    private function resolveSources(): Collection
    {
        $id = $this->argument('source');
        if (is_string($id) && $id !== '') {
            $source = Source::query()->find($id);
            if ($source === null) {
                $this->error('Source not found.');

                return collect();
            }

            return collect([$source]);
        }

        return Source::query()->orderBy('created_at')->get();
    }
}
