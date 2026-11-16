<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\BrainRepositoryData;
use App\Brain\Data\ItemData;
use App\Brain\Data\LinkEdgeData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Data\SectionData;
use App\Brain\Data\SkippedFileData;
use App\Brain\Data\StructuredTableData;
use App\Brain\Data\ValidationIssueData;
use App\Brain\Enums\ItemStatusEnum;
use App\Brain\Exceptions\DenylistHitException;
use App\Brain\Readers\BrainReader;
use App\Enums\ItemChangeKindEnum;
use App\Enums\QuotaMetricEnum;
use App\Enums\SourceProviderEnum;
use App\Enums\SyncStatusEnum;
use App\Exceptions\QuotaExceededException;
use App\Jobs\EmbedItemSectionJob;
use App\Models\Item;
use App\Models\ItemChange;
use App\Models\ItemLink;
use App\Models\ItemSection;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\StructuredRow;
use App\Models\StructuredTable;
use App\Models\VaultVersion;
use App\Notifications\SyncFailedNotification;
use App\Notifications\SyncFinishedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Read-only ingest: mirror/read a source via the brain reader and upsert the index.
 * Re-sync prunes items (and cascaded sections / links / tables / search rows) that
 * the reader no longer returns for this source — deleted files or newly skipped paths.
 *
 * Scheduled and webhook syncs pass `$isForced = false`: when the source is still at the
 * indexed commit they stop after the fetch (#199). After a successful sync, materialised
 * proposals whose change is now in the source are marked merged.
 */
final class SourceSyncService
{
    /** @var list<string> Sections created or changed by the running sync, embedded once it commits (#226). */
    private array $sectionsToEmbed = [];

    public function __construct(
        private readonly DenylistGuardService $denylist = new DenylistGuardService,
        private readonly BrainReader $reader = new BrainReader,
        private readonly QuotaEnforcementService $quotas = new QuotaEnforcementService,
        private readonly SearchIndexWriterService $searchIndex = new SearchIndexWriterService,
        private readonly MergedProposalDetectorService $mergedProposals = new MergedProposalDetectorService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(Source $source, bool $isForced = true): array
    {
        $statusBeforeSync = $source->sync_status;
        $this->sectionsToEmbed = [];

        $source->forceFill([
            'sync_status' => SyncStatusEnum::Syncing,
        ])->save();

        try {
            $this->denylist->assertAllowed($source->name, $source->remote_url, $source->local_path);

            if ($source->provider === SourceProviderEnum::Github && ! $this->githubToken()) {
                return $this->fail($source, [
                    'reason' => 'github_token_missing',
                    'message' => 'GitHub sync refused: INSTITUTA_GITHUB_TOKEN is not configured.',
                    'items_upserted' => 0,
                    'sections_upserted' => 0,
                    'links_upserted' => 0,
                    'skipped' => [],
                    'issues' => [],
                    'versions_upserted' => 0,
                ]);
            }

            [$readRoot, $commit, $versions] = $this->prepareWorkspace($source);

            if (! $isForced && $this->isUnchanged($source, $commit, $statusBeforeSync)) {
                return $this->skipUnchanged($source, $commit);
            }

            try {
                $this->quotas->consume(
                    organisationId: $source->organisation_id,
                    metric: QuotaMetricEnum::SourceSyncs,
                    companyId: $source->company_id,
                );
            } catch (QuotaExceededException $exception) {
                return $this->fail($source, [
                    'reason' => 'quota_exceeded',
                    'message' => $exception->getMessage(),
                    'items_upserted' => 0,
                    'sections_upserted' => 0,
                    'links_upserted' => 0,
                    'skipped' => [],
                    'issues' => [],
                    'versions_upserted' => 0,
                ]);
            }

            $repo = $this->reader->read(new ReadRequestData(
                path: $readRoot,
                source: $source->name,
                commit: $commit,
                denylist: $this->denylist->tokens(),
            ));

            $report = DB::transaction(function () use ($source, $repo, $commit, $versions): array {
                $counts = $this->upsertIndex($source, $repo, $commit);
                $versionCount = $this->upsertVersions($source, $versions, $commit);
                $this->recordVaultVersion($source, $commit);

                return [
                    'items_upserted' => $counts['items'],
                    'sections_upserted' => $counts['sections'],
                    'links_upserted' => $counts['links'],
                    'skipped' => array_map(
                        static fn (SkippedFileData $skip): array => $skip->toArray(),
                        $repo->skipped,
                    ),
                    'issues' => array_map(
                        static fn (ValidationIssueData $issue): array => $issue->toArray(),
                        $repo->issues,
                    ),
                    'versions_upserted' => $versionCount,
                    'commit' => $commit,
                    'read_path' => $repo->path,
                ];
            });

            $source->forceFill([
                'last_synced_commit' => $commit,
                'sync_status' => SyncStatusEnum::Ok,
                'last_synced_at' => now(),
                'last_sync_report' => $report,
            ])->save();

            $this->mergedProposals->markMergedIn($source);
            $this->queueEmbeddings();

            activity('sync')
                ->performedOn($source)
                ->event('source.synced')
                ->withProperties([
                    'organisation_id' => $source->organisation_id,
                    'status' => SyncStatusEnum::Ok->value,
                    'commit' => $commit,
                    'items_upserted' => $report['items_upserted'],
                    'sections_upserted' => $report['sections_upserted'],
                ])
                ->log('source.synced');

            if ($report['skipped'] !== []) {
                app(ActivityEventLoggerService::class)->scannerSkips($source, $report['skipped']);
            }

            app(NotifyOrganisationUsersService::class)->notify(
                $source->organisation_id,
                new SyncFinishedNotification(
                    source: $source,
                    itemsUpserted: (int) $report['items_upserted'],
                ),
            );

            return $report;
        } catch (DenylistHitException $exception) {
            return $this->fail($source, [
                'reason' => 'denylist_hit',
                'message' => $exception->getMessage(),
                'matched_token' => $exception->matchedToken,
                'items_upserted' => 0,
                'sections_upserted' => 0,
                'links_upserted' => 0,
                'skipped' => [],
                'issues' => [],
                'versions_upserted' => 0,
            ]);
        } catch (Throwable $exception) {
            return $this->fail($source, [
                'reason' => 'sync_failed',
                'message' => $exception->getMessage(),
                'items_upserted' => 0,
                'sections_upserted' => 0,
                'links_upserted' => 0,
                'skipped' => [],
                'issues' => [],
                'versions_upserted' => 0,
            ]);
        }
    }

    /**
     * Queues an embedding for every section the sync created or changed (#226), so hybrid search
     * never runs on stale vectors. Nothing while embeddings are off; `instituta:embed` stays for
     * a full backfill (first enable, model change).
     *
     * @side-effect Dispatches EmbedItemSectionJob per section.
     */
    private function queueEmbeddings(): void
    {
        if (! (bool) config('ai.embeddings.enabled')) {
            return;
        }

        foreach (array_unique($this->sectionsToEmbed) as $sectionId) {
            EmbedItemSectionJob::dispatch($sectionId);
        }

        $this->sectionsToEmbed = [];
    }

    /**
     * A scheduled or webhook sync has nothing to do when the source is still at the commit
     * the index was built from. Only real git commits count: a plain folder reports `HEAD`
     * and is always re-read.
     */
    private function isUnchanged(Source $source, string $commit, SyncStatusEnum $statusBeforeSync): bool
    {
        return $statusBeforeSync === SyncStatusEnum::Ok
            && preg_match('/\A[0-9a-f]{40}\z/', $commit) === 1
            && $source->last_synced_commit === $commit;
    }

    /**
     * Records the check without rebuilding the index or spending the tenant's sync quota.
     *
     * @return array<string, mixed>
     */
    private function skipUnchanged(Source $source, string $commit): array
    {
        $source->forceFill([
            'sync_status' => SyncStatusEnum::Ok,
            'last_synced_at' => now(),
        ])->save();

        return ['skipped_unchanged' => true, 'commit' => $commit, 'items_upserted' => 0];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function fail(Source $source, array $report): array
    {
        $source->forceFill([
            'sync_status' => SyncStatusEnum::Failed,
            'last_synced_at' => now(),
            'last_sync_report' => $report,
        ])->save();

        activity('sync')
            ->performedOn($source)
            ->event('source.sync_failed')
            ->withProperties([
                'organisation_id' => $source->organisation_id,
                'status' => SyncStatusEnum::Failed->value,
                'reason' => $report['reason'] ?? null,
                'message' => $report['message'] ?? null,
            ])
            ->log('source.sync_failed');

        app(NotifyOrganisationUsersService::class)->notify(
            $source->organisation_id,
            new SyncFailedNotification(
                source: $source,
                reason: (string) ($report['reason'] ?? 'sync_failed'),
            ),
        );

        return $report;
    }

    /**
     * @return array{0: string, 1: string, 2: list<array{label: string, commit: string}>}
     */
    private function prepareWorkspace(Source $source): array
    {
        return match ($source->provider) {
            SourceProviderEnum::Local => $this->prepareLocal($source),
            SourceProviderEnum::Git, SourceProviderEnum::Github => $this->prepareGitMirror($source),
        };
    }

    /**
     * @return array{0: string, 1: string, 2: list<array{label: string, commit: string}>}
     */
    private function prepareLocal(Source $source): array
    {
        $root = $source->local_path;
        if ($root === null || $root === '') {
            throw new RuntimeException('Local source is missing local_path.');
        }

        $commit = $this->resolveLocalCommit($root);
        $versions = [['label' => 'HEAD', 'commit' => $commit]];

        return [$this->withOptionalSubdir($root, $source->path), $commit, $versions];
    }

    /**
     * @return array{0: string, 1: string, 2: list<array{label: string, commit: string}>}
     */
    private function prepareGitMirror(Source $source): array
    {
        $remote = $source->remote_url;
        if ($remote === null || $remote === '') {
            throw new RuntimeException('Git source is missing remote_url.');
        }

        $mirror = storage_path('app/instituta/sources/'.$source->id);
        if (! is_dir(dirname($mirror))) {
            mkdir(dirname($mirror), 0775, true);
        }

        $cloneUrl = $this->authenticatedRemote($remote, $source->provider);

        if (is_dir($mirror.DIRECTORY_SEPARATOR.'.git') || (is_dir($mirror) && ! $this->directoryIsEmpty($mirror))) {
            $fetch = Process::path($mirror)->run(['git', 'fetch', '--tags', '--prune', 'origin']);
            if ($fetch->failed()) {
                throw new RuntimeException('git fetch failed: '.trim($fetch->errorOutput().' '.$fetch->output()));
            }
        } else {
            if (is_dir($mirror)) {
                rmdir($mirror);
            }
            $clone = Process::run(['git', 'clone', $cloneUrl, $mirror]);
            if ($clone->failed()) {
                throw new RuntimeException('git clone failed: '.trim($clone->errorOutput().' '.$clone->output()));
            }
        }

        if ($source->ref !== null && $source->ref !== '') {
            $checkout = Process::path($mirror)->run(['git', 'checkout', $source->ref]);
            if ($checkout->failed()) {
                throw new RuntimeException('git checkout failed: '.trim($checkout->errorOutput().' '.$checkout->output()));
            }
        }

        $rev = Process::path($mirror)->run(['git', 'rev-parse', 'HEAD']);
        if ($rev->failed()) {
            throw new RuntimeException('git rev-parse failed: '.trim($rev->errorOutput().' '.$rev->output()));
        }
        $commit = trim($rev->output());
        if ($commit === '') {
            $commit = 'HEAD';
        }

        $versions = $this->listGitTags($mirror);
        if ($versions === []) {
            $versions = [['label' => 'HEAD', 'commit' => $commit]];
        }

        return [$this->withOptionalSubdir($mirror, $source->path), $commit, $versions];
    }

    /**
     * @return list<array{label: string, commit: string}>
     */
    private function listGitTags(string $mirror): array
    {
        $tags = Process::path($mirror)->run([
            'git',
            'tag',
            '-l',
            '--format=%(refname:short)%09%(objectname)',
        ]);

        if ($tags->failed()) {
            return [];
        }

        $versions = [];
        foreach (preg_split("/\r\n|\n|\r/", trim($tags->output())) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$label, $commit] = $parts;
            if ($label === '' || $commit === '') {
                continue;
            }
            $versions[] = ['label' => $label, 'commit' => $commit];
        }

        return $versions;
    }

    private function resolveLocalCommit(string $root): string
    {
        if (! is_dir($root.DIRECTORY_SEPARATOR.'.git')) {
            return 'HEAD';
        }

        $rev = Process::path($root)->run(['git', 'rev-parse', 'HEAD']);
        if ($rev->failed()) {
            return 'HEAD';
        }

        $commit = trim($rev->output());

        return $commit !== '' ? $commit : 'HEAD';
    }

    private function withOptionalSubdir(string $root, ?string $subdir): string
    {
        if ($subdir === null || $subdir === '') {
            return $root;
        }

        return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($subdir, DIRECTORY_SEPARATOR);
    }

    private function authenticatedRemote(string $remote, SourceProviderEnum $provider): string
    {
        if ($provider !== SourceProviderEnum::Github) {
            return $remote;
        }

        $token = $this->githubToken();
        if ($token === null) {
            return $remote;
        }

        if (str_starts_with($remote, 'https://github.com/')) {
            return 'https://x-access-token:'.$token.'@github.com/'.substr($remote, strlen('https://github.com/'));
        }

        return $remote;
    }

    private function githubToken(): ?string
    {
        $token = config('instituta.ingest.github_token');
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        return trim($token);
    }

    private function directoryIsEmpty(string $path): bool
    {
        $entries = scandir($path);
        if ($entries === false) {
            return true;
        }

        return count(array_diff($entries, ['.', '..'])) === 0;
    }

    /**
     * @param  list<array{label: string, commit: string}>  $versions
     */
    private function upsertVersions(Source $source, array $versions, string $fallbackCommit): int
    {
        $count = 0;
        if ($versions === []) {
            $versions = [['label' => 'HEAD', 'commit' => $fallbackCommit]];
        }

        foreach ($versions as $version) {
            SourceVersion::query()->updateOrCreate(
                [
                    'source_id' => $source->id,
                    'label' => $version['label'],
                ],
                [
                    'commit' => $version['commit'],
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * Records the organisation's new vault version: the latest commit of every source.
     *
     * The latest version is the one with the highest sequence, not the newest `created_at`:
     * syncs in the same second (scheduler, webhooks) tied on the timestamp and reused a label,
     * failing the unique (organisation, label) index.
     */
    private function recordVaultVersion(Source $source, string $commit): void
    {
        $latest = null;
        $latestSequence = 0;

        foreach (VaultVersion::query()->where('organisation_id', $source->organisation_id)->get() as $version) {
            $sequence = preg_match('/^instituta@v(\d+)$/', $version->label, $matches) === 1 ? (int) $matches[1] : 0;

            if ($sequence > $latestSequence) {
                $latest = $version;
                $latestSequence = $sequence;
            }
        }

        $sourceCommits = is_array($latest?->source_commits) ? $latest->source_commits : [];
        $sourceCommits[$source->id] = $commit;

        VaultVersion::query()->create([
            'organisation_id' => $source->organisation_id,
            'label' => 'instituta@v'.($latestSequence + 1),
            'source_commits' => $sourceCommits,
            'signature' => null,
        ]);
    }

    /**
     * @return array{items: int, sections: int, links: int}
     */
    private function upsertIndex(Source $source, BrainRepositoryData $repo, string $commit): array
    {
        $items = 0;
        $sections = 0;
        $pathToItemId = [];

        foreach ($repo->items as $itemData) {
            $item = $this->upsertItem($source, $itemData, $commit);
            $isItemChanged = $item->wasChanged(['content_hash', 'title', 'summary', 'area', 'visibility', 'status', 'superseded_by', 'frontmatter']);
            $pathToItemId[$itemData->path] = $item->id;
            $items++;
            ['count' => $sectionCount, 'changed_anchors' => $changedAnchors] = $this->upsertSections($source, $item, $itemData->sections);
            $sections += $sectionCount;

            if ($item->wasRecentlyCreated) {
                $this->recordChange($item, ItemChangeKindEnum::Created, null);
            } elseif ($isItemChanged || $changedAnchors !== []) {
                $this->recordChange($item, ItemChangeKindEnum::Updated, $changedAnchors);
            }
            $this->upsertTables($source, $item, $itemData->tables);
        }

        $links = $this->upsertLinks($source, $repo->edges, $pathToItemId, $this->linkKeysToItemId($repo, $pathToItemId));
        $this->pruneStaleItems($source, array_keys($pathToItemId));

        return [
            'items' => $items,
            'sections' => $sections,
            'links' => $links,
        ];
    }

    /**
     * Remove index rows for this source whose paths are absent from the current read.
     *
     * Scoped to `source_id` only so other organisations and sources stay untouched.
     * Cascades clear sections, links and structured tables; SQLite FTS rows are
     * dropped explicitly because mass deletes skip model observers.
     *
     * @param  list<string>  $keptPaths
     */
    private function pruneStaleItems(Source $source, array $keptPaths): void
    {
        $staleQuery = Item::query()->where('source_id', $source->id);

        if ($keptPaths !== []) {
            $staleQuery->whereNotIn('path', $keptPaths);
        }

        $staleItemIds = array_values(array_map(
            static fn (mixed $itemId): string => (string) $itemId,
            $staleQuery->pluck('id')->all(),
        ));

        if ($staleItemIds === []) {
            return;
        }

        $this->searchIndex->deleteByItemIds($staleItemIds);

        foreach (Item::query()->whereIn('id', $staleItemIds)->get() as $staleItem) {
            $this->recordChange($staleItem, ItemChangeKindEnum::Removed, null);
        }

        Item::query()->whereIn('id', $staleItemIds)->delete();
    }

    /**
     * Appends what this sync did to an item (#228); removed items keep this row as a tombstone.
     *
     * @param  list<string>|null  $changedAnchors  For an update, the sections whose content changed, appeared or disappeared.
     */
    private function recordChange(Item $item, ItemChangeKindEnum $kind, ?array $changedAnchors): void
    {
        ItemChange::query()->create([
            'organisation_id' => $item->organisation_id,
            'company_id' => $item->company_id,
            'product_id' => $item->product_id,
            'source_id' => $item->source_id,
            'item_id' => $item->id,
            'kind' => $kind,
            'stable_key' => $item->stable_key,
            'path' => $item->path,
            'title' => $item->title,
            'area' => $item->area,
            'visibility' => $item->visibility,
            'status' => $item->status,
            'superseded_by' => $item->superseded_by,
            'commit' => $item->commit,
            'changed_sections' => $changedAnchors,
            'changed_at' => now(),
        ]);
    }

    private function upsertItem(Source $source, ItemData $itemData, string $commit): Item
    {
        /** @var Item $item */
        $item = Item::query()->updateOrCreate(
            [
                'source_id' => $source->id,
                'path' => $itemData->path,
            ],
            [
                'organisation_id' => $source->organisation_id,
                'company_id' => $source->company_id,
                'product_id' => $source->product_id,
                'stable_key' => $itemData->stableKey,
                'type' => $itemData->type,
                'area' => $itemData->area,
                'title' => $itemData->title,
                'summary' => $itemData->summary,
                'visibility' => $itemData->visibility,
                'commit' => $itemData->citation->commit ?? $commit,
                'content_hash' => hash('sha256', $itemData->path."\n".$itemData->body),
                'token_estimate' => $itemData->tokenEstimate(),
                'frontmatter' => $itemData->frontmatter === [] ? null : $itemData->frontmatter,
                'decision_date' => $itemData->decisionDate,
                'effective_date' => $itemData->effectiveDate,
                'review_date' => $itemData->reviewDate,
                'resolution_key' => $itemData->resolutionKey,
                'status' => ItemStatusEnum::fromFrontmatter($itemData->frontmatter),
                'superseded_by' => is_string($itemData->frontmatter['superseded_by'] ?? null) && trim($itemData->frontmatter['superseded_by']) !== ''
                    ? trim($itemData->frontmatter['superseded_by'])
                    : null,
            ],
        );

        return $item;
    }

    /**
     * @param  list<SectionData>  $sections
     * @return array{count: int, changed_anchors: list<string>}
     */
    private function upsertSections(Source $source, Item $item, array $sections): array
    {
        $count = 0;
        $seenAnchors = [];
        $changedAnchors = [];

        foreach ($sections as $section) {
            $stored = ItemSection::query()->updateOrCreate(
                [
                    'item_id' => $item->id,
                    'anchor' => $section->anchor,
                ],
                [
                    'organisation_id' => $source->organisation_id,
                    'company_id' => $source->company_id,
                    'product_id' => $source->product_id,
                    'heading' => $section->heading,
                    'level' => $section->level,
                    'position' => $section->position,
                    'body' => $section->body,
                    'markdown' => $section->markdown === '' ? null : $section->markdown,
                    'token_estimate' => $section->tokenEstimate(),
                    'blocks' => $section->blocks === [] ? null : array_map(
                        static fn ($block): array => $block->toArray(),
                        $section->blocks,
                    ),
                ],
            );
            // New or changed text needs a fresh embedding (#226); unchanged sections keep theirs.
            if ($stored->wasRecentlyCreated || $stored->wasChanged(['heading', 'body'])) {
                $this->sectionsToEmbed[] = $stored->id;
            }

            if ($stored->wasRecentlyCreated || $stored->wasChanged(['heading', 'body', 'markdown'])) {
                $changedAnchors[] = $section->anchor;
            }

            $seenAnchors[] = $section->anchor;
            $count++;
        }

        $removedSections = ItemSection::query()->where('item_id', $item->id);

        if ($seenAnchors !== []) {
            $removedSections->whereNotIn('anchor', $seenAnchors);
        }

        foreach ($removedSections->pluck('anchor')->all() as $removedAnchor) {
            $changedAnchors[] = (string) $removedAnchor;
        }

        $removedSections->delete();

        return ['count' => $count, 'changed_anchors' => $changedAnchors];
    }

    /**
     * Replace structured tables for an item with the reader's current tables.
     *
     * @param  list<StructuredTableData>  $tables
     */
    private function upsertTables(Source $source, Item $item, array $tables): void
    {
        StructuredRow::query()->where('item_id', $item->id)->delete();
        StructuredTable::query()->where('item_id', $item->id)->delete();

        foreach ($tables as $tableData) {
            $table = StructuredTable::query()->create([
                'item_id' => $item->id,
                'organisation_id' => $source->organisation_id,
                'company_id' => $source->company_id,
                'product_id' => $source->product_id,
                'table_key' => $tableData->tableKey,
                'columns' => $tableData->columns,
                'position' => $tableData->position,
            ]);

            $rowPosition = 0;

            foreach ($tableData->values as $rowValues) {
                StructuredRow::query()->create([
                    'structured_table_id' => $table->id,
                    'item_id' => $item->id,
                    'organisation_id' => $source->organisation_id,
                    'company_id' => $source->company_id,
                    'product_id' => $source->product_id,
                    'values' => $rowValues,
                    'position' => $rowPosition,
                ]);
                $rowPosition++;
            }
        }
    }

    /**
     * Lower-cased names a link may use for an item (#242): its title, stable key and file name
     * without `.md`. Wiki links usually name the title (`[[Knowledge Base]]`), which the file name
     * alone does not match. On a clash the first item read keeps the name.
     *
     * @param  array<string, string>  $pathToItemId
     * @return array<string, string>
     */
    private function linkKeysToItemId(BrainRepositoryData $repo, array $pathToItemId): array
    {
        $keysToItemId = [];

        foreach ($repo->items as $itemData) {
            $itemId = $pathToItemId[$itemData->path] ?? null;

            if ($itemId === null) {
                continue;
            }

            foreach ([$itemData->title, $itemData->stableKey, basename($itemData->path, '.md')] as $name) {
                $key = mb_strtolower(trim($name));

                if ($key !== '' && ! isset($keysToItemId[$key])) {
                    $keysToItemId[$key] = $itemId;
                }
            }
        }

        return $keysToItemId;
    }

    /**
     * @param  list<LinkEdgeData>  $edges
     * @param  array<string, string>  $pathToItemId
     * @param  array<string, string>  $keysToItemId  From {@see self::linkKeysToItemId()}.
     */
    private function upsertLinks(Source $source, array $edges, array $pathToItemId, array $keysToItemId): int
    {
        $fromIds = array_values($pathToItemId);
        if ($fromIds !== []) {
            ItemLink::query()->whereIn('from_item_id', $fromIds)->delete();
        }

        $count = 0;
        foreach ($edges as $edge) {
            $fromId = $pathToItemId[$edge->fromPath] ?? null;
            if ($fromId === null) {
                continue;
            }

            $toId = $this->resolveLinkTarget($edge->target, $pathToItemId, $keysToItemId);

            ItemLink::query()->create([
                'from_item_id' => $fromId,
                'to_item_id' => $toId,
                'organisation_id' => $source->organisation_id,
                'company_id' => $source->company_id,
                'product_id' => $source->product_id,
                'target' => $edge->target,
                'relation' => $edge->relation,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Resolves a link target by path, file name, title or stable key (case-insensitive), with
     * any `#heading` or `|label` left out.
     *
     * @param  array<string, string>  $pathToItemId
     * @param  array<string, string>  $keysToItemId
     */
    private function resolveLinkTarget(string $target, array $pathToItemId, array $keysToItemId): ?string
    {
        $normalized = trim($target);
        $normalized = preg_replace('/^\[\[|\]\]$/', '', $normalized) ?? $normalized;
        $normalized = trim(explode('|', explode('#', $normalized)[0])[0]);
        $normalized = rawurldecode(ltrim($normalized, './'));

        if ($normalized === '') {
            return null;
        }

        if (isset($pathToItemId[$normalized])) {
            return $pathToItemId[$normalized];
        }

        foreach ($pathToItemId as $path => $id) {
            if ($path === $normalized.'.md' || str_ends_with($path, '/'.$normalized)) {
                return $id;
            }
        }

        $key = mb_strtolower(preg_replace('/\.md$/i', '', $normalized) ?? $normalized);

        return $keysToItemId[$key] ?? $keysToItemId[mb_strtolower(basename($key))] ?? null;
    }
}
