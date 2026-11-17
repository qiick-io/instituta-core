<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\ItemStatusEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Support\TokenEstimator;
use App\Contracts\SearchIndexContract;
use App\Enums\ContractErrorCodeEnum;
use App\Exceptions\ContractException;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\ContextFreeze;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemChange;
use App\Models\ItemLink;
use App\Models\ItemSection;
use App\Models\Principal;
use App\Models\Product;
use App\Models\StructuredTable;
use App\Models\VaultVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Database-backed contract operations for HTTP MCP / REST (perimeter first).
 */
final class IndexedContractService
{
    /**
     * The most sections one getSections call reads (#225).
     */
    public const int MAX_REQUESTED_SECTIONS = 20;

    /**
     * The context pack's token budget (#224): default, floor and ceiling.
     */
    public const int CONTEXT_DEFAULT_BUDGET = 4000;

    public const int CONTEXT_MIN_BUDGET = 500;

    public const int CONTEXT_MAX_BUDGET = 16000;

    /**
     * How many search hits the context pack considers before the budget decides.
     */
    private const int CONTEXT_CANDIDATE_HITS = 30;

    public function __construct(
        private readonly ContractAuditService $audit = new ContractAuditService,
        private readonly ?SearchIndexContract $search = null,
    ) {}

    private function searchDriver(): SearchIndexContract
    {
        return $this->search ?? app(SearchIndexContract::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function scopes(PerimeterData $perimeter, ?ApiKey $apiKey): array
    {
        $principal = Principal::query()->find($perimeter->principalId);
        $grants = $principal === null
            ? collect()
            : $principal->grants()->active()->get();

        $payload = [
            'principal' => [
                'id' => $perimeter->principalId,
                'type' => $perimeter->principalType->value,
            ],
            'perimeter' => $perimeter->toArray(),
            'scopes' => $grants->map(static fn (Grant $grant): array => [
                'scope_type' => $grant->scope_type->value,
                'scope_id' => $grant->scope_id,
                'role' => $grant->role->value,
                'max_visibility' => $grant->max_visibility->value,
                'path_prefix' => $grant->path_prefix,
                'area' => $grant->area,
            ])->values()->all(),
            ...$this->freezeMeta($perimeter),
        ];

        $this->audit->record('instituta_scopes', $perimeter, $apiKey);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function list(
        PerimeterData $perimeter,
        ?ApiKey $apiKey,
        ?string $area = null,
        ?string $type = null,
        ?string $cursor = null,
        int $limit = 25,
    ): array {
        $limit = max(1, min(100, $limit));

        $query = $this->itemsQuery($perimeter)
            ->orderBy('id');

        if ($area !== null && $area !== '') {
            $query->where('area', $area);
        }
        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }
        if ($cursor !== null && $cursor !== '') {
            $query->where('id', '>', $cursor);
        }

        /** @var Collection<int, Item> $items */
        $items = $query->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        $page = $items->take($limit);
        $summaries = $page->map(fn (Item $item): array => $this->summary($item, $perimeter))->values()->all();

        /** @var list<string> $itemIds */
        $itemIds = array_values($page->pluck('id')->all());

        $this->audit->record(
            'instituta_list',
            $perimeter,
            $apiKey,
            $itemIds,
        );

        return [
            'items' => $summaries,
            'next_cursor' => $hasMore ? $page->last()?->id : null,
            ...$this->freezeMeta($perimeter),
        ];
    }

    /**
     * Keyword, semantic or hybrid search (#217). Hybrid (the default) merges both when embeddings
     * are on and falls back to keyword otherwise; `mode` in the answer says what ran.
     *
     * @return array<string, mixed>
     */
    public function search(
        PerimeterData $perimeter,
        ?ApiKey $apiKey,
        string $q,
        ?string $area = null,
        int $limit = 20,
        string $mode = HybridSearchService::MODE_HYBRID,
    ): array {
        try {
            ['mode' => $modeUsed, 'results' => $results] = $this->runSearch($perimeter, $q, $area, $limit, $mode);
        } catch (RuntimeException $exception) {
            $this->audit->record('instituta_search', $perimeter, $apiKey, []);

            throw $exception;
        }

        /** @var list<string> $itemIds */
        $itemIds = array_values(array_unique(array_map(
            static fn (array $hit): string => (string) $hit['id'],
            $results,
        )));

        $this->audit->record('instituta_search', $perimeter, $apiKey, $itemIds);

        return [
            'mode' => $modeUsed,
            'results' => $results,
            ...$this->freezeMeta($perimeter),
        ];
    }

    /**
     * A ready-to-use context pack for a task in one call (#224), instead of search, outline and
     * section reads one after the other. Best first and within `$budget` tokens (#230 estimates):
     * a product's resolved entries as one-liners, then the most relevant sections already read
     * (hybrid search, active entries first), then what did not fit (`omitted`), so the agent can
     * fetch more with get_sections. One audit row lists every item included.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the database driver has no full-text search.
     */
    public function context(
        PerimeterData $perimeter,
        ?ApiKey $apiKey,
        string $q,
        ?string $productId = null,
        ?string $area = null,
        int $budget = self::CONTEXT_DEFAULT_BUDGET,
    ): array {
        $budget = max(self::CONTEXT_MIN_BUDGET, min(self::CONTEXT_MAX_BUDGET, $budget));
        $tokensUsed = 0;
        $includedItemIds = [];
        $resolvedEntries = [];
        $sections = [];
        $omitted = [];

        $productEntries = $productId !== null && $productId !== ''
            ? ($this->resolvedEntries($perimeter, $productId, $area, false) ?? [])
            : [];

        foreach ($productEntries as $productEntry) {
            $oneLiner = [
                'resolution_key' => $productEntry['resolution_key'],
                'title' => $productEntry['title'],
                'summary' => $productEntry['summary'],
                'status' => $productEntry['status'],
                'citation' => $productEntry['citation'],
            ];
            $cost = TokenEstimator::forText((string) json_encode($oneLiner));

            if ($tokensUsed + $cost > $budget) {
                $omitted[] = ['kind' => 'resolved_entry', 'resolution_key' => $productEntry['resolution_key'], 'path' => $productEntry['origin']['path'], 'token_estimate' => $cost];

                continue;
            }

            $tokensUsed += $cost;
            $resolvedEntries[] = $oneLiner;
        }

        ['mode' => $modeUsed, 'results' => $hits] = $this->runSearch($perimeter, $q, $area, self::CONTEXT_CANDIDATE_HITS, HybridSearchService::MODE_HYBRID);

        $hitItems = $this->itemsQuery($perimeter)->whereIn('id', array_column($hits, 'id'))->get()->keyBy('id');

        foreach ($hits as $hit) {
            /** @var Item|null $item */
            $item = $hitItems->get($hit['id']);
            $anchor = $hit['anchor'] ?? null;

            if ($item === null || ! is_string($anchor)) {
                continue;
            }

            /** @var ItemSection|null $section */
            $section = $item->sections()->where('anchor', $anchor)->first();

            if ($section === null) {
                continue;
            }

            if ($tokensUsed + $section->token_estimate > $budget) {
                $omitted[] = ['kind' => 'section', 'item' => $item->id, 'anchor' => $anchor, 'title' => $item->title, 'token_estimate' => $section->token_estimate];

                continue;
            }

            $tokensUsed += $section->token_estimate;
            $includedItemIds[] = $item->id;
            $sections[] = [
                'item' => $item->id,
                'stable_key' => $item->stable_key,
                'title' => $item->title,
                'anchor' => $section->anchor,
                'heading' => $section->heading,
                'markdown' => $section->markdown ?? $section->body,
                'status' => $item->status->value,
                'superseded_by' => $item->superseded_by,
                'token_estimate' => $section->token_estimate,
                'citation' => [...$this->citation($item), 'section' => $section->anchor],
            ];
        }

        $this->audit->record('instituta_context', $perimeter, $apiKey, array_values(array_unique($includedItemIds)), $tokensUsed);

        return [
            'q' => $q,
            'product_id' => $productId,
            'mode' => $modeUsed,
            'budget' => $budget,
            'token_estimate' => $tokensUsed,
            'resolved' => $resolvedEntries,
            'sections' => $sections,
            'omitted' => $omitted,
            ...$this->freezeMeta($perimeter),
        ];
    }

    /**
     * What changed in the brain since a moment (#228): items created, updated (with the sections
     * whose content changed) and removed, oldest first, from the sync's change log. `$since` is an
     * ISO 8601 date-time or a vault version label (`instituta@v12`: changes after that version).
     * Under a freeze, only changes up to the frozen version show. Cursor-paginated by change id.
     *
     * @return array<string, mixed>
     *
     * @throws ContractException When `$since` is neither a date-time nor a known version label.
     */
    public function changes(
        PerimeterData $perimeter,
        ?ApiKey $apiKey,
        string $since,
        ?string $area = null,
        ?string $cursor = null,
        int $limit = 50,
    ): array {
        $limit = max(1, min(200, $limit));
        $query = ItemChange::query()
            ->withinPerimeter($perimeter)
            ->where('changed_at', '>', $this->changesSince($perimeter, $since))
            ->orderBy('id');

        $freeze = $this->activeFreeze($perimeter);

        if ($freeze !== null) {
            $query->where('changed_at', '<=', $freeze->vaultVersion->created_at ?? CarbonImmutable::createFromTimestamp(0));
        }

        if ($area !== null && $area !== '') {
            $query->where('area', $area);
        }

        if ($cursor !== null && $cursor !== '') {
            $query->where('id', '>', $cursor);
        }

        $changes = $query->limit($limit + 1)->get();
        $hasMore = $changes->count() > $limit;
        $page = $changes->take($limit)->values();

        $this->audit->record('instituta_changes', $perimeter, $apiKey, array_values(array_unique(array_map(
            static fn (ItemChange $change): string => $change->item_id,
            $page->all(),
        ))));

        return [
            'since' => $since,
            'changes' => $page->map(static fn (ItemChange $change): array => [
                'kind' => $change->kind->value,
                'item' => $change->item_id,
                'stable_key' => $change->stable_key,
                'title' => $change->title,
                'area' => $change->area,
                'status' => $change->status->value,
                'superseded_by' => $change->superseded_by,
                'changed_sections' => $change->changed_sections,
                'changed_at' => $change->changed_at->toIso8601String(),
                'citation' => ['source' => $change->source_id, 'path' => $change->path, 'section' => null, 'commit' => $change->commit],
            ])->all(),
            'next_cursor' => $hasMore ? $page->last()?->id : null,
            ...$this->freezeMeta($perimeter),
        ];
    }

    /**
     * @throws ContractException When the value is neither a date-time nor a version label of the perimeter's organisation.
     */
    private function changesSince(PerimeterData $perimeter, string $since): CarbonImmutable
    {
        if (preg_match('/^instituta@v\d+$/', $since) === 1) {
            [$organisationId] = app(PerimeterScopeColumnsService::class)->columnsFor($perimeter);
            $version = VaultVersion::query()->where('organisation_id', $organisationId)->where('label', $since)->first();

            if ($version?->created_at === null) {
                throw new ContractException(ContractErrorCodeEnum::NotFound, 'No vault version with this label in your perimeter.');
            }

            return CarbonImmutable::parse($version->created_at);
        }

        try {
            return CarbonImmutable::parse($since);
        } catch (Throwable) {
            throw new ContractException(ContractErrorCodeEnum::ValidationFailed, 'since must be an ISO 8601 date-time or a vault version label (instituta@vN).');
        }
    }

    /**
     * The search itself, without the audit row, so the context pack (#224) can reuse it.
     *
     * @return array{mode: string, results: list<array<string, mixed>>}
     *
     * @throws RuntimeException When the database driver has no full-text search.
     */
    private function runSearch(PerimeterData $perimeter, string $q, ?string $area, int $limit, string $mode): array
    {
        $limit = max(1, min(50, $limit));

        /** @var list<string> $allowedIds */
        $allowedIds = $this->itemsQuery($perimeter)->pluck('id')->all();

        $isSemanticAvailable = HybridSearchService::isSemanticAvailable();
        $modeUsed = match (true) {
            $mode === HybridSearchService::MODE_KEYWORD, ! $isSemanticAvailable => HybridSearchService::MODE_KEYWORD,
            $mode === HybridSearchService::MODE_SEMANTIC => HybridSearchService::MODE_SEMANTIC,
            default => HybridSearchService::MODE_HYBRID,
        };

        $keywordHits = $modeUsed === HybridSearchService::MODE_SEMANTIC
            ? []
            : $this->searchDriver()->search($perimeter, $q, $allowedIds, $area, $limit);

        $results = $keywordHits;

        if ($modeUsed !== HybridSearchService::MODE_KEYWORD) {
            $hybrid = app(HybridSearchService::class);
            $results = $hybrid->fuse($keywordHits, $hybrid->semanticHits($perimeter, $q, $allowedIds, $area, $limit), $limit);
        }

        // Current entries first (#218); the sort is stable, so relevance order holds within each group.
        usort($results, static fn (array $left, array $right): int => (int) (($right['status'] ?? 'active') === 'active')
            <=> (int) (($left['status'] ?? 'active') === 'active'));

        return ['mode' => $modeUsed, 'results' => $results];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(PerimeterData $perimeter, ?ApiKey $apiKey, string $id): ?array
    {
        $item = $this->findItem($perimeter, $id);
        if ($item === null) {
            $this->audit->record('instituta_get', $perimeter, $apiKey, []);

            return null;
        }

        $item->load(['sections', 'structuredTables.rows', 'source']);

        $payload = [
            ...$this->summary($item, $perimeter),
            'frontmatter' => $item->frontmatter ?? (object) [],
            'sections' => $item->sections
                ->sortBy('position')
                ->values()
                ->map(static fn (ItemSection $section): array => [
                    'anchor' => $section->anchor,
                    'heading' => $section->heading,
                    'level' => $section->level,
                    'position' => $section->position,
                    'body' => $section->body,
                    'markdown' => $section->markdown,
                    'section_hash' => ItemSection::hashOf($section->markdown),
                    'token_estimate' => $section->token_estimate,
                    'blocks' => $section->blocks ?? [],
                ])
                ->all(),
            'tables' => $item->structuredTables
                ->sortBy('position')
                ->values()
                ->map(static fn (StructuredTable $table): array => [
                    'table_key' => $table->table_key,
                    'columns' => $table->columns,
                    'rows' => $table->rows->sortBy('position')->values()->map(
                        static fn ($row): array => $row->values,
                    )->all(),
                ])
                ->all(),
            ...$this->freezeMeta($perimeter),
        ];

        $this->audit->record('instituta_get', $perimeter, $apiKey, [$item->id]);

        return $payload;
    }

    /**
     * One section of an item by anchor, or null when the item or section is outside the perimeter.
     *
     * @return array<string, mixed>|null
     */
    public function getSection(
        PerimeterData $perimeter,
        ?ApiKey $apiKey,
        string $id,
        string $anchor,
    ): ?array {
        $item = $this->findItem($perimeter, $id);
        $this->audit->record('instituta_get_section', $perimeter, $apiKey, $item === null ? [] : [$item->id]);

        return $item === null ? null : $this->sectionPayload($perimeter, $item, $anchor);
    }

    /**
     * Several sections in one call (#225): each entry answers like getSection, and a missing
     * item or anchor (or one outside the perimeter) is reported as `error: not_found` on its own
     * entry instead of failing the call. One audit row lists every item read.
     *
     * @param  list<array{item: string, anchor: string}>  $requestedSections
     * @return array{sections: list<array<string, mixed>>}
     */
    public function getSections(PerimeterData $perimeter, ?ApiKey $apiKey, array $requestedSections): array
    {
        /** @var array<string, Item|null> $itemsByRequestedId */
        $itemsByRequestedId = [];
        $answeredSections = [];

        foreach ($requestedSections as $requestedSection) {
            $requestedId = $requestedSection['item'];

            if (! array_key_exists($requestedId, $itemsByRequestedId)) {
                $itemsByRequestedId[$requestedId] = $this->findItem($perimeter, $requestedId);
            }

            $item = $itemsByRequestedId[$requestedId];
            $section = $item === null ? null : $this->sectionPayload($perimeter, $item, $requestedSection['anchor']);

            $answeredSections[] = [
                'item' => $requestedId,
                'anchor' => $requestedSection['anchor'],
                ...($section === null ? ['error' => ContractErrorCodeEnum::NotFound->value] : ['section' => $section]),
            ];
        }

        $readItemIds = array_values(array_unique(array_map(
            static fn (Item $item): string => $item->id,
            array_filter($itemsByRequestedId),
        )));
        $this->audit->record('instituta_get_sections', $perimeter, $apiKey, $readItemIds);

        return ['sections' => $answeredSections];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sectionPayload(PerimeterData $perimeter, Item $item, string $anchor): ?array
    {
        /** @var ItemSection|null $section */
        $section = $item->sections()->where('anchor', $anchor)->first();

        if ($section === null) {
            return null;
        }

        return [
            'id' => $item->id,
            'stable_key' => $item->stable_key,
            'anchor' => $section->anchor,
            'heading' => $section->heading,
            'level' => $section->level,
            'body' => $section->body,
            'markdown' => $section->markdown,
            'section_hash' => ItemSection::hashOf($section->markdown),
            'content_hash' => $item->content_hash,
            'token_estimate' => $section->token_estimate,
            'blocks' => $section->blocks ?? [],
            'citation' => $this->citation($item),
            ...$this->freezeMeta($perimeter),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array<string, mixed>|null
     */
    public function table(
        PerimeterData $perimeter,
        ?ApiKey $apiKey,
        string $id,
        string $tableKey,
        ?array $filter = null,
    ): ?array {
        $item = $this->findItem($perimeter, $id);
        if ($item === null) {
            $this->audit->record('instituta_table', $perimeter, $apiKey, []);

            return null;
        }

        /** @var StructuredTable|null $table */
        $table = $item->structuredTables()->where('table_key', $tableKey)->first();
        if ($table === null) {
            $this->audit->record('instituta_table', $perimeter, $apiKey, [$item->id]);

            return null;
        }

        $rows = $table->rows()->orderBy('position')->get();
        if ($filter !== null && $filter !== []) {
            $rows = $rows->filter(static function ($row) use ($filter): bool {
                foreach ($filter as $column => $expected) {
                    if (($row->values[$column] ?? null) !== $expected) {
                        return false;
                    }
                }

                return true;
            })->values();
        }

        $this->audit->record('instituta_table', $perimeter, $apiKey, [$item->id]);

        return [
            'id' => $item->id,
            'table_key' => $table->table_key,
            'columns' => $table->columns,
            'rows' => $rows->map(static fn ($row): array => $row->values)->all(),
            'citation' => $this->citation($item),
            ...$this->freezeMeta($perimeter),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function outline(PerimeterData $perimeter, ?ApiKey $apiKey, string $id): ?array
    {
        $item = $this->findItem($perimeter, $id);
        if ($item === null) {
            $this->audit->record('instituta_outline', $perimeter, $apiKey, []);

            return null;
        }

        $sections = $item->sections()->orderBy('position')->get();

        $this->audit->record('instituta_outline', $perimeter, $apiKey, [$item->id]);

        return [
            'id' => $item->id,
            'stable_key' => $item->stable_key,
            'path' => $item->path,
            'token_estimate' => $item->token_estimate,
            'sections' => $sections->map(static fn (ItemSection $section): array => [
                'anchor' => $section->anchor,
                'heading' => $section->heading,
                'level' => $section->level,
                'token_estimate' => $section->token_estimate,
                'block_types' => array_values(array_unique(array_map(
                    static fn (array $block): string => (string) ($block['type'] ?? 'unknown'),
                    $section->blocks ?? [],
                ))),
            ])->all(),
            'citation' => $this->citation($item),
            ...$this->freezeMeta($perimeter),
        ];
    }

    /**
     * The entry that applies per resolution key, most specific scope first (product over company
     * over organisation). An active entry always wins over a draft, deprecated or superseded one
     * (#218); an inactive entry shows only when no active one exists for its key, or with
     * `$includeInactive`, and carries its status either way.
     *
     * @return array<string, mixed>
     */
    public function resolve(
        PerimeterData $perimeter,
        ?ApiKey $apiKey,
        string $productId,
        ?string $area = null,
        bool $includeInactive = false,
    ): array {
        $entries = $this->resolvedEntries($perimeter, $productId, $area, $includeInactive);

        if ($entries === null) {
            $this->audit->record('instituta_resolve', $perimeter, $apiKey, []);

            return ['entries' => [], ...$this->freezeMeta($perimeter)];
        }

        $this->audit->record('instituta_resolve', $perimeter, $apiKey);

        return [
            'product_id' => $productId,
            'entries' => $entries,
            // What reading every resolved entry in full costs (#230).
            'token_estimate' => array_sum(array_column($entries, 'token_estimate')),
            ...$this->freezeMeta($perimeter),
        ];
    }

    /**
     * The resolved entries of a product without the audit row, so the context pack (#224) can
     * reuse them; null when the product is unknown or outside the perimeter.
     *
     * @return list<array<string, mixed>>|null
     */
    private function resolvedEntries(PerimeterData $perimeter, string $productId, ?string $area, bool $includeInactive): ?array
    {
        $product = Product::query()->find($productId);
        $company = $product?->company;

        // Caller must be able to see the product scope (or a wider covering grant).
        if ($product === null || $company === null || ! $this->productVisibleInPerimeter($perimeter, $product, $company)) {
            return null;
        }

        $layers = $this->resolutionLayers($product, $company);
        /** @var array<string, array<string, mixed>> $resolved */
        $resolved = [];

        // Organisation → company → product so product wins on the same resolution_key.
        foreach (array_reverse($layers) as $layer) {
            $query = Item::query()
                ->where('organisation_id', $company->organisation_id)
                ->whereNotNull('resolution_key')
                ->where('resolution_key', '!=', '');

            match ($layer['scope_type']) {
                'product' => $query->where('product_id', $product->id),
                'company' => $query->where('company_id', $company->id)->whereNull('product_id'),
                'organisation' => $query->whereNull('company_id')->whereNull('product_id'),
                default => null,
            };

            if ($area !== null && $area !== '') {
                $query->where('area', $area);
            }

            $query->withinPerimeter($this->layerPerimeter($perimeter, $layer));

            foreach ($this->applyFreezeFilter($query, $perimeter)->get() as $item) {
                /** @var Item $item */
                $key = (string) $item->resolution_key;
                $isCurrent = $item->status->isCurrent();

                // A more specific inactive entry never replaces an active one (#218).
                if (! $isCurrent && ! $includeInactive && ($resolved[$key]['status'] ?? null) === ItemStatusEnum::Active->value) {
                    continue;
                }

                $resolved[$key] = [
                    'resolution_key' => (string) $item->resolution_key,
                    'title' => $item->title,
                    'summary' => $item->summary,
                    'visibility' => $item->visibility->value,
                    'token_estimate' => $item->token_estimate,
                    ...$this->validity($item),
                    'origin' => [
                        'scope_type' => $layer['scope_type'],
                        'scope_id' => $layer['scope_id'],
                        'source' => $item->source_id,
                        'path' => $item->path,
                        'commit' => $item->commit,
                    ],
                    'citation' => $this->citation($item),
                ];
            }
        }

        ksort($resolved);

        return array_values($resolved);
    }

    /**
     * @return array<string, mixed>
     */
    public function graph(
        PerimeterData $perimeter,
        ?ApiKey $apiKey,
        string $id,
        int $depth = 1,
    ): array {
        $depth = max(1, min(2, $depth));
        $item = $this->findItem($perimeter, $id);
        if ($item === null) {
            $this->audit->record('instituta_graph', $perimeter, $apiKey, []);

            return ['nodes' => [], 'edges' => [], ...$this->freezeMeta($perimeter)];
        }

        $nodes = [
            $item->id => [
                'id' => $item->id,
                'title' => $item->title,
                'area' => $item->area,
            ],
        ];
        $edges = [];
        $frontier = [$item->id];

        for ($level = 0; $level < $depth; $level++) {
            $next = [];
            $links = ItemLink::query()
                ->whereIn('from_item_id', $frontier)
                ->get();

            foreach ($links as $link) {
                $toId = $link->to_item_id;
                if ($toId === null) {
                    continue;
                }

                $neighbour = $this->findItem($perimeter, $toId);
                if ($neighbour === null) {
                    continue;
                }

                $nodes[$neighbour->id] = [
                    'id' => $neighbour->id,
                    'title' => $neighbour->title,
                    'area' => $neighbour->area,
                ];
                $edges[] = [
                    'from' => $link->from_item_id,
                    'to' => $neighbour->id,
                    'relation' => $link->relation,
                ];
                $next[] = $neighbour->id;
            }
            $frontier = $next;
        }

        $this->audit->record('instituta_graph', $perimeter, $apiKey, array_keys($nodes));

        return [
            'nodes' => array_values($nodes),
            'edges' => $edges,
            ...$this->freezeMeta($perimeter),
        ];
    }

    private function findItem(PerimeterData $perimeter, string $id): ?Item
    {
        /** @var Item|null $item */
        $item = $this->itemsQuery($perimeter)
            ->matchingReference($id)
            ->first();

        return $item;
    }

    /**
     * @return Builder<Item>
     */
    private function itemsQuery(PerimeterData $perimeter): Builder
    {
        return $this->applyFreezeFilter(
            Item::query()->withinPerimeter($perimeter),
            $perimeter,
        );
    }

    /**
     * @param  Builder<Item>  $query
     * @return Builder<Item>
     */
    private function applyFreezeFilter(Builder $query, PerimeterData $perimeter): Builder
    {
        $freeze = $this->activeFreeze($perimeter);
        if ($freeze === null) {
            return $query;
        }

        $vault = $freeze->vaultVersion;
        if ($vault === null) {
            return $query->whereRaw('1 = 0');
        }

        $commits = $vault->source_commits;
        if ($commits === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function (Builder $builder) use ($commits): void {
            foreach ($commits as $sourceId => $commit) {
                $builder->orWhere(function (Builder $inner) use ($sourceId, $commit): void {
                    $inner->where('source_id', $sourceId)
                        ->where('commit', $commit);
                });
            }
        });

        return $query;
    }

    private function activeFreeze(PerimeterData $perimeter): ?ContextFreeze
    {
        $query = ContextFreeze::query()->active()->with('vaultVersion');

        return match ($perimeter->scopeType) {
            ScopeTypeEnum::Organisation => $query
                ->where('organisation_id', $perimeter->scopeId)
                ->whereNull('company_id')
                ->whereNull('product_id')
                ->first(),
            ScopeTypeEnum::Company => $query
                ->where('company_id', $perimeter->scopeId)
                ->whereNull('product_id')
                ->first(),
            ScopeTypeEnum::Product => $query
                ->where('product_id', $perimeter->scopeId)
                ->first(),
        };
    }

    /**
     * @return array{frozen?: true, vault_version_id?: string, label?: string}
     */
    private function freezeMeta(PerimeterData $perimeter): array
    {
        $freeze = $this->activeFreeze($perimeter);
        if ($freeze === null) {
            return [];
        }

        /** @var VaultVersion|null $vault */
        $vault = $freeze->vaultVersion;
        $label = $vault?->label;
        if ($label === null || $label === '') {
            return [
                'frozen' => true,
                'vault_version_id' => $freeze->vault_version_id,
            ];
        }

        return [
            'frozen' => true,
            'vault_version_id' => $freeze->vault_version_id,
            'label' => $label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Item $item, PerimeterData $perimeter): array
    {
        unset($perimeter);

        return [
            'id' => $item->id,
            'stable_key' => $item->stable_key,
            'title' => $item->title,
            'summary' => $item->summary,
            'area' => $item->area,
            'type' => $item->type,
            'visibility' => $item->visibility->value,
            'token_estimate' => $item->token_estimate,
            ...$this->validity($item),
            'origin' => [
                'scope_type' => $item->product_id !== null
                    ? 'product'
                    : ($item->company_id !== null ? 'company' : 'organisation'),
                'scope_id' => $item->product_id ?? $item->company_id ?? $item->organisation_id,
                'source' => $item->source_id,
                'path' => $item->path,
                'commit' => $item->commit,
                'content_hash' => $item->content_hash,
            ],
            'citation' => $this->citation($item),
        ];
    }

    /**
     * Whether the entry is still valid (#218), so an agent prefers current rules and says when it
     * relies on an old one.
     *
     * @return array{status: string, superseded_by: string|null, decision_date: string|null, effective_date: string|null, review_date: string|null, is_review_overdue: bool}
     */
    private function validity(Item $item): array
    {
        return [
            'status' => $item->status->value,
            'superseded_by' => $item->superseded_by,
            'decision_date' => $item->decision_date?->toDateString(),
            'effective_date' => $item->effective_date?->toDateString(),
            'review_date' => $item->review_date?->toDateString(),
            'is_review_overdue' => $item->review_date !== null && $item->review_date->isPast(),
        ];
    }

    /**
     * @return array{source: string, path: string, section: null, commit: string|null}
     */
    private function citation(Item $item): array
    {
        return [
            'source' => $item->source_id,
            'path' => $item->path,
            'section' => null,
            'commit' => $item->commit,
        ];
    }

    private function productVisibleInPerimeter(
        PerimeterData $perimeter,
        Product $product,
        Company $company,
    ): bool {
        return match ($perimeter->scopeType) {
            ScopeTypeEnum::Product => $perimeter->scopeId === $product->id,
            ScopeTypeEnum::Company => $perimeter->scopeId === $company->id,
            ScopeTypeEnum::Organisation => $perimeter->scopeId === $company->organisation_id,
        };
    }

    /**
     * @return list<array{scope_type: string, scope_id: string}>
     */
    private function resolutionLayers(Product $product, Company $company): array
    {
        $layers = [
            [
                'scope_type' => 'product',
                'scope_id' => $product->id,
            ],
        ];

        if ($product->inherits_from_parent) {
            $layers[] = [
                'scope_type' => 'company',
                'scope_id' => $company->id,
            ];
            $layers[] = [
                'scope_type' => 'organisation',
                'scope_id' => $company->organisation_id,
            ];
        }

        return $layers;
    }

    /**
     * @param  array{scope_type: string, scope_id: string}  $layer
     */
    private function layerPerimeter(PerimeterData $perimeter, array $layer): PerimeterData
    {
        return new PerimeterData(
            principalId: $perimeter->principalId,
            principalType: $perimeter->principalType,
            scopeType: ScopeTypeEnum::from($layer['scope_type']),
            scopeId: $layer['scope_id'],
            role: $perimeter->role,
            maxVisibility: $perimeter->maxVisibility,
            pathPrefix: $perimeter->pathPrefix,
            area: $perimeter->area,
        );
    }
}
