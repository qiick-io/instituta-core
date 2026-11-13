<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Post-fetch upgrade steps for a self-hosted Instituta install.
 */
#[AsCommand(name: 'instituta:upgrade')]
class UpgradeCommand extends Command
{
    protected $signature = 'instituta:upgrade
        {--force : Allow production APP_ENV}
        {--no-migrate : Skip php artisan migrate --force}
        {--no-permissions : Skip instituta:sync-permissions}
        {--prune-permissions : Pass --prune to instituta:sync-permissions}';

    protected $description = 'Run post-fetch upgrade steps (migrate, permissions sync, cache clear). Never use create-project to update.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (app()->environment('production') && ! $force) {
            $this->error('Refusing to run in production without --force.');

            return self::FAILURE;
        }

        $this->warn('instituta:upgrade does not run composer or npm. Pull/fetch code first, then upgrade.');

        if (! $this->option('no-migrate')) {
            $this->info('Running migrate --force…');
            $code = $this->call('migrate', ['--force' => true]);

            if ($code !== self::SUCCESS) {
                return $code;
            }
        }

        if (! $this->option('no-permissions')) {
            $this->info('Running instituta:sync-permissions…');
            $args = [];

            if ($this->option('prune-permissions')) {
                $args['--prune'] = true;
            }

            $code = $this->call('instituta:sync-permissions', $args);

            if ($code !== self::SUCCESS) {
                return $code;
            }
        }

        $this->info('Clearing caches…');
        $this->call('optimize:clear');

        $this->info('Instituta upgrade complete.');

        return self::SUCCESS;
    }
}
