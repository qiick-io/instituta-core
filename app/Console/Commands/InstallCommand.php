<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\ManagesDotEnvTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * First-run installer for a fresh Instituta tree (CI and operators).
 */
#[AsCommand(name: 'instituta:install')]
class InstallCommand extends Command
{
    use ManagesDotEnvTrait;

    protected $signature = 'instituta:install
        {--force : Allow overwriting an existing .env / running when APP_ENV looks like production}
        {--no-npm : Skip npm install / build}';

    protected $description = 'First-run setup: write .env, migrate, optional npm build';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if ($this->looksLikeProduction() && ! $force) {
            $this->error('Refusing: APP_ENV looks like production. Pass --force if you really mean it.');

            return self::FAILURE;
        }

        if (! File::exists(base_path('.env.example'))) {
            $this->error('.env.example missing.');

            return self::FAILURE;
        }

        $envPath = base_path('.env');

        if (! File::exists($envPath) || $force) {
            File::copy(base_path('.env.example'), $envPath);
            $this->info('Wrote .env from .env.example');
        }

        $sqlite = database_path('database.sqlite');

        if (! File::exists($sqlite)) {
            File::put($sqlite, '');
            $this->line('Created database/database.sqlite');
        }

        if ($this->call('key:generate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->call('storage:link');

        if (! $this->option('no-npm')) {
            $this->info('npm ci…');
            $install = Process::timeout(600)->run(['npm', 'ci']);

            if ($install->failed()) {
                $this->error($install->errorOutput());

                return self::FAILURE;
            }

            $this->info('npm run build…');
            $build = Process::timeout(600)->run(['npm', 'run', 'build']);

            if ($build->failed()) {
                $this->error($build->errorOutput());

                return self::FAILURE;
            }
        }

        $this->info('Instituta install complete.');

        return self::SUCCESS;
    }
}
