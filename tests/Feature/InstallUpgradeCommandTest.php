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

it('runs instituta:upgrade migrate permissions sync and cache clear without create-project', function (): void {
    $this->artisan('instituta:upgrade', [
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('does not run composer or npm')
        ->expectsOutputToContain('Running instituta:sync-permissions')
        ->assertSuccessful();
});

it('skips key:generate when APP_KEY is already present on disk', function (): void {
    $envBackup = null;
    $envPath = base_path('.env');

    if (File::exists($envPath)) {
        $envBackup = File::get($envPath);
    }

    try {
        $existingKey = 'base64:'.base64_encode(random_bytes(32));
        File::put($envPath, "APP_KEY={$existingKey}\nAPP_NAME=Instituta\nAPP_URL=http://localhost\n");

        $this->artisan('instituta:install', [
            '--no-npm' => true,
            '--no-owner' => true,
        ])
            ->expectsOutputToContain('APP_KEY already set; skipping key:generate.')
            ->assertSuccessful();

        expect(File::get($envPath))->toContain('APP_KEY='.$existingKey);
    } finally {
        if ($envBackup === null) {
            File::delete($envPath);
        } else {
            File::put($envPath, $envBackup);
        }
    }
});

it('writes a non-empty APP_KEY when --force copies an empty key from .env.example', function (): void {
    $envBackup = null;
    $envPath = base_path('.env');

    if (File::exists($envPath)) {
        $envBackup = File::get($envPath);
    }

    try {
        // Stale in-memory key must not skip generation after --force rewrites .env.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->artisan('instituta:install', [
            '--force' => true,
            '--no-npm' => true,
            '--no-owner' => true,
        ])->assertSuccessful();

        $env = File::get($envPath);

        expect($env)->toMatch('/^APP_KEY=.+/m')
            ->and($env)->not->toMatch('/^APP_KEY=\s*$/m');
    } finally {
        if ($envBackup === null) {
            File::delete($envPath);
        } else {
            File::put($envPath, $envBackup);
        }
    }
});
