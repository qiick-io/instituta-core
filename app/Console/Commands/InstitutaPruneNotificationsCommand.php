<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deletes database notifications older than the retention window so the table stays bounded.
 */
#[AsCommand(name: 'instituta:prune-notifications')]
class InstitutaPruneNotificationsCommand extends Command
{
    protected $signature = 'instituta:prune-notifications
        {--days=30 : Delete notifications older than this many days}';

    protected $description = 'Prune old in-app notifications';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = DB::table('notifications')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} notification(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
