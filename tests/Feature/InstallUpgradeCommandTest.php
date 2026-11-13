<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('writes app name url and minimal stack from instituta:install', function (): void {
    $envBackup = null;
    $envPath = base_path('.env');

    if (File::exists($envPath)) {
        $envBackup = File::get($envPath);
    }

    try {
        $this->artisan('instituta:install', [
            '--force' => true,
            '--no-npm' => true,
            '--no-owner' => true,
            '--app-name' => 'Beta Brain',
            '--app-url' => 'https://instituta-core.test',
            '--stack' => 'minimal',
        ])->assertSuccessful();

        $env = File::get($envPath);

        expect($env)->toContain('APP_NAME=')
            ->and($env)->toMatch('/^APP_NAME=.*Beta Brain/m')
            ->and($env)->toContain('APP_URL=https://instituta-core.test')
            ->and($env)->toContain('QUEUE_CONNECTION=database')
            ->and($env)->toContain('CACHE_STORE=database');
    } finally {
        if ($envBackup === null) {
            File::delete($envPath);
        } else {
            File::put($envPath, $envBackup);
        }
    }
});

it('refuses an unknown install stack', function (): void {
    $this->artisan('instituta:install', [
        '--force' => true,
        '--no-npm' => true,
        '--no-owner' => true,
        '--stack' => 'sail',
    ])->assertFailed();
});

it('runs instituta:upgrade migrate and cache clear without create-project', function (): void {
    $this->artisan('instituta:upgrade', [
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('does not run composer or npm')
        ->expectsOutputToContain('Permissions sync: skipped')
        ->assertSuccessful();
});
