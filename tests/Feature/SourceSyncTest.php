<?php

declare(strict_types=1);

use App\Brain\Exceptions\DenylistHitException;
use App\Enums\SourceAdapterEnum;
use App\Enums\SourceProviderEnum;
use App\Enums\SyncStatusEnum;
use App\Jobs\SyncSourceJob;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\StructuredRow;
use App\Models\StructuredTable;
use App\Models\VaultVersion;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config([
        'instituta.ingest.denylist' => ['klover', 'cybergrant', 'zerotredici'],
        'instituta.ingest.github_token' => null,
    ]);
});

it('refuses to add a source that hits the denylist', function (): void {
    $organisation = Organisation::factory()->create();

    expect(fn () => app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Acme Klover Docs',
        provider: SourceProviderEnum::Local,
        localPath: base_path('tests/Brain/Fixtures/native'),
    ))->toThrow(DenylistHitException::class);

    expect(Source::query()->count())->toBe(0);
});

it('syncs a local source and indexes fixture items', function (): void {
    $organisation = Organisation::factory()->create();
    $fixture = base_path('tests/Brain/Fixtures/native');

    $source = app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Native fixture',
        provider: SourceProviderEnum::Local,
        localPath: $fixture,
        ref: null,
        path: null,
        isShared: true,
    );

    $report = app(SourceSyncService::class)->sync($source->fresh());

    $source->refresh();

    expect($source->sync_status)->toBe(SyncStatusEnum::Ok)
        ->and($report['items_upserted'])->toBeGreaterThan(0)
        ->and(Item::query()->where('source_id', $source->id)->count())->toBeGreaterThan(0)
        ->and(Item::query()->where('source_id', $source->id)->where('path', 'rules/coding.md')->exists())->toBeTrue()
        ->and(SourceVersion::query()->where('source_id', $source->id)->where('label', 'HEAD')->exists())->toBeTrue()
        ->and(VaultVersion::query()->where('organisation_id', $organisation->id)->count())->toBe(1)
        ->and($source->last_sync_report)->toHaveKeys(['items_upserted', 'skipped', 'issues', 'commit']);
});

it('mirrors a git source with Process::fake and indexes from the mirror', function (): void {
    $organisation = Organisation::factory()->create();

    $source = app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Git fixture',
        provider: SourceProviderEnum::Git,
        remoteUrl: 'https://example.test/qiick/brain.git',
        ref: 'main',
    );

    $mirror = storage_path('app/instituta/sources/'.$source->id);
    File::ensureDirectoryExists($mirror);
    File::copyDirectory(base_path('tests/Brain/Fixtures/native'), $mirror);
    File::ensureDirectoryExists($mirror.'/.git');

    // Symfony quotes argv on macOS/Linux ("'git' 'fetch' …"), so avoid literal 'git fetch *' patterns.
    Process::fake(function (PendingProcess $process) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        if (str_contains($command, 'rev-parse')) {
            return Process::result(output: "abcdef0123456789abcdef0123456789abcdef01\n");
        }

        if (str_contains($command, 'tag')) {
            return Process::result(output: "v1.0.0\tabcdef0123456789abcdef0123456789abcdef01\n");
        }

        return Process::result();
    });

    $report = app(SourceSyncService::class)->sync($source->fresh());

    $source->refresh();

    Process::assertRan(function (PendingProcess $process): bool {
        $command = $process->command;
        $haystack = is_array($command) ? implode(' ', $command) : (string) $command;

        return str_contains($haystack, 'git') && str_contains($haystack, 'fetch');
    });

    expect($source->sync_status)->toBe(SyncStatusEnum::Ok)
        ->and($source->last_synced_commit)->toBe('abcdef0123456789abcdef0123456789abcdef01')
        ->and($report['items_upserted'])->toBeGreaterThan(0)
        ->and(Item::query()->where('source_id', $source->id)->count())->toBeGreaterThan(0)
        ->and(SourceVersion::query()->where('source_id', $source->id)->where('label', 'v1.0.0')->exists())->toBeTrue()
        ->and(VaultVersion::query()->where('organisation_id', $organisation->id)->exists())->toBeTrue();
});

it('refuses github sync when no token is configured', function (): void {
    $organisation = Organisation::factory()->create();

    $source = app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Remote knowledge',
        provider: SourceProviderEnum::Github,
        remoteUrl: 'https://github.com/example/knowledge',
    );

    Process::fake();

    $report = app(SourceSyncService::class)->sync($source->fresh());

    $source->refresh();

    expect($source->sync_status)->toBe(SyncStatusEnum::Failed)
        ->and($report['reason'])->toBe('github_token_missing')
        ->and(Item::query()->where('source_id', $source->id)->count())->toBe(0);

    Process::assertNothingRan();
});

it('persists structured tables and rows from a shadcn registry source', function (): void {
    $organisation = Organisation::factory()->create();
    $fixture = base_path('tests/Brain/Fixtures/shadcn-registry');

    $source = app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Registry fixture',
        provider: SourceProviderEnum::Local,
        localPath: $fixture,
        adapter: SourceAdapterEnum::ShadcnRegistry,
        isShared: true,
    );

    app(SourceSyncService::class)->sync($source->fresh());

    $registryItem = Item::query()
        ->where('source_id', $source->id)
        ->where('path', 'registry.json')
        ->firstOrFail();

    $table = StructuredTable::query()
        ->where('item_id', $registryItem->id)
        ->where('table_key', 'registry')
        ->firstOrFail();

    expect(StructuredRow::query()->where('structured_table_id', $table->id)->count())->toBeGreaterThan(0);

    app(SourceSyncService::class)->sync($source->fresh());

    expect(StructuredTable::query()->where('item_id', $registryItem->id)->count())->toBe(1)
        ->and(StructuredRow::query()->where('item_id', $registryItem->id)->count())->toBeGreaterThan(0);
});

it('adds a source and syncs via artisan commands', function (): void {
    $organisation = Organisation::factory()->create();
    $fixture = base_path('tests/Brain/Fixtures/native');

    $this->artisan('instituta:source:add', [
        'name' => 'CLI local',
        '--organisation' => $organisation->id,
        '--provider' => 'local',
        '--local-path' => $fixture,
        '--shared' => true,
    ])->assertSuccessful();

    $source = Source::query()->where('name', 'CLI local')->firstOrFail();

    $this->artisan('instituta:sync', [
        'source' => $source->id,
    ])->assertSuccessful();

    expect(Item::query()->where('source_id', $source->id)->count())->toBeGreaterThan(0);
});

it('queues SyncSourceJob when --queue is passed', function (): void {
    Queue::fake();

    $source = Source::factory()->create([
        'provider' => SourceProviderEnum::Local,
        'local_path' => base_path('tests/Brain/Fixtures/native'),
    ]);

    $this->artisan('instituta:sync', [
        'source' => $source->id,
        '--queue' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SyncSourceJob::class, fn (SyncSourceJob $job): bool => $job->sourceId === $source->id);
});

it('prunes deleted paths on re-sync including sections links tables and search rows', function (): void {
    $root = sys_get_temp_dir().'/instituta-prune-delete-'.uniqid('', true);
    File::ensureDirectoryExists($root.'/notes');
    File::put($root.'/instituta.json', json_encode([
        'format' => 'instituta',
        'format_version' => 1,
        'name' => 'Prune delete fixture',
        'default_visibility' => 'internal',
        'areas' => ['notes'],
    ], JSON_THROW_ON_ERROR));
    File::put($root.'/notes/keep.md', <<<'MD'
---
title: Keep me
type: note
area: notes
visibility: internal
---

# Keep me

See [[drop]].
MD);
    File::put($root.'/notes/drop.md', <<<'MD'
---
title: Drop me
type: note
area: notes
visibility: internal
---

# Drop me

Body that should leave the index.
MD);

    $organisation = Organisation::factory()->create();
    $source = app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Prune delete source',
        provider: SourceProviderEnum::Local,
        localPath: $root,
        isShared: true,
    );

    $otherOrganisation = Organisation::factory()->create();
    $otherSource = app(SourceRegistryService::class)->add(
        organisation: $otherOrganisation,
        name: 'Other org source',
        provider: SourceProviderEnum::Local,
        localPath: base_path('tests/Brain/Fixtures/native'),
        isShared: true,
    );
    app(SourceSyncService::class)->sync($otherSource->fresh());
    $otherItemCount = Item::query()->where('source_id', $otherSource->id)->count();

    app(SourceSyncService::class)->sync($source->fresh());

    $keep = Item::query()->where('source_id', $source->id)->where('path', 'notes/keep.md')->firstOrFail();
    $drop = Item::query()->where('source_id', $source->id)->where('path', 'notes/drop.md')->firstOrFail();
    $dropSectionIds = ItemSection::query()->where('item_id', $drop->id)->pluck('id')->all();

    expect($dropSectionIds)->not->toBeEmpty()
        ->and(ItemLink::query()->where('from_item_id', $keep->id)->where('to_item_id', $drop->id)->exists())->toBeTrue();

    $table = StructuredTable::query()->create([
        'item_id' => $drop->id,
        'organisation_id' => $organisation->id,
        'company_id' => $source->company_id,
        'product_id' => $source->product_id,
        'table_key' => 'tokens',
        'columns' => ['name'],
        'position' => 0,
    ]);
    StructuredRow::query()->create([
        'structured_table_id' => $table->id,
        'item_id' => $drop->id,
        'organisation_id' => $organisation->id,
        'company_id' => $source->company_id,
        'product_id' => $source->product_id,
        'values' => ['name' => 'teal'],
        'position' => 0,
    ]);

    File::delete($root.'/notes/drop.md');

    app(SourceSyncService::class)->sync($source->fresh());

    expect(Item::query()->where('source_id', $source->id)->where('path', 'notes/drop.md')->exists())->toBeFalse()
        ->and(Item::query()->where('source_id', $source->id)->where('path', 'notes/keep.md')->exists())->toBeTrue()
        ->and(ItemSection::query()->where('item_id', $drop->id)->count())->toBe(0)
        ->and(ItemLink::query()->where(function ($query) use ($drop): void {
            $query->where('from_item_id', $drop->id)->orWhere('to_item_id', $drop->id);
        })->count())->toBe(0)
        ->and(StructuredTable::query()->where('item_id', $drop->id)->count())->toBe(0)
        ->and(StructuredRow::query()->where('item_id', $drop->id)->count())->toBe(0)
        ->and(Item::query()->where('source_id', $otherSource->id)->count())->toBe($otherItemCount);

    if (Schema::hasTable('item_sections_fts')) {
        expect(DB::table('item_sections_fts')->whereIn('section_id', $dropSectionIds)->count())->toBe(0);
    }

    File::deleteDirectory($root);
});

it('prunes paths newly skipped by hygiene on re-sync', function (): void {
    $root = sys_get_temp_dir().'/instituta-prune-skip-'.uniqid('', true);
    File::ensureDirectoryExists($root.'/notes');
    File::put($root.'/instituta.json', json_encode([
        'format' => 'instituta',
        'format_version' => 1,
        'name' => 'Prune skip fixture',
        'default_visibility' => 'internal',
        'areas' => ['notes'],
    ], JSON_THROW_ON_ERROR));
    File::put($root.'/notes/keep.md', <<<'MD'
---
title: Keep me
type: note
area: notes
visibility: internal
---

# Keep me

Stays indexed.
MD);
    File::put($root.'/notes/soon-skipped.md', <<<'MD'
---
title: Soon skipped
type: note
area: notes
visibility: internal
---

# Soon skipped

Will move under .claude.
MD);

    $organisation = Organisation::factory()->create();
    $source = app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Prune skip source',
        provider: SourceProviderEnum::Local,
        localPath: $root,
        isShared: true,
    );

    app(SourceSyncService::class)->sync($source->fresh());

    $skippedItem = Item::query()
        ->where('source_id', $source->id)
        ->where('path', 'notes/soon-skipped.md')
        ->firstOrFail();
    $skippedSectionIds = ItemSection::query()->where('item_id', $skippedItem->id)->pluck('id')->all();

    File::ensureDirectoryExists($root.'/.claude');
    File::move($root.'/notes/soon-skipped.md', $root.'/.claude/soon-skipped.md');

    $report = app(SourceSyncService::class)->sync($source->fresh());

    expect(Item::query()->where('id', $skippedItem->id)->exists())->toBeFalse()
        ->and(Item::query()->where('source_id', $source->id)->where('path', 'notes/keep.md')->exists())->toBeTrue()
        ->and(ItemSection::query()->where('item_id', $skippedItem->id)->count())->toBe(0)
        ->and(collect($report['skipped'])->pluck('path')->all())->toContain('.claude/soon-skipped.md');

    if (Schema::hasTable('item_sections_fts')) {
        expect(DB::table('item_sections_fts')->whereIn('section_id', $skippedSectionIds)->count())->toBe(0);
    }

    File::deleteDirectory($root);
});
