<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Before the first stable release, every add_/alter_ migration is folded into the create_
 * migration of its table (decision 2026-11-20). The .frozen baseline is removed.
 */
it('has only create_ migrations and no frozen baseline', function (): void {
    $migrationDirectory = database_path('migrations');
    $migrationFiles = collect(scandir($migrationDirectory) ?: [])
        ->filter(fn (string $name): bool => str_ends_with($name, '.php'))
        ->values();

    expect($migrationFiles->filter(fn (string $name): bool => (bool) preg_match(
        '/_(add_|align_|alter_|reapply_|use_)/',
        $name,
    )))->toBeEmpty();

    expect(file_exists($migrationDirectory.'/.frozen'))->toBeFalse();
});

it('creates folded columns on a fresh migrate', function (): void {
    expect(Schema::hasColumns('users', [
        'first_name',
        'last_name',
        'locale',
        'timezone',
        'notification_sound_enabled',
        'two_factor_secret',
    ]))->toBeTrue();

    expect(Schema::hasColumns('companies', ['logo_path']))->toBeTrue();
    expect(Schema::hasColumns('products', ['logo_path']))->toBeTrue();
    expect(Schema::hasColumns('sources', [
        'last_sync_report',
        'sync_interval_minutes',
        'webhook_secret',
    ]))->toBeTrue();
    expect(Schema::hasColumns('items', ['status', 'superseded_by', 'token_estimate']))->toBeTrue();
    expect(Schema::hasColumns('item_sections', [
        'markdown',
        'token_estimate',
        'embedding_vector',
        'embedding_content_hash',
    ]))->toBeTrue();
    expect(Schema::hasColumns('grants', ['areas', 'path_prefixes', 'user_group_id']))->toBeTrue();
    expect(Schema::hasColumns('api_keys', ['areas', 'path_prefixes']))->toBeTrue();
    expect(Schema::hasColumns('activity_log', ['organisation_id']))->toBeTrue();
    expect(Schema::hasColumns('proposals', [
        'decision_note',
        'frontmatter_changes',
        'base_content_hash',
        'materialisation_status',
        'merged_at',
    ]))->toBeTrue();
    expect(Schema::hasTable('user_group_brain_access'))->toBeTrue();
    expect(Schema::hasColumns('user_group_brain_access', [
        'max_visibility',
        'areas',
        'path_prefixes',
    ]))->toBeTrue();
});
