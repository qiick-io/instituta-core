<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\ScopeTypeEnum;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\ContextFreeze;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemSection;
use App\Models\Principal;
use App\Models\Product;
use App\Models\StructuredTable;
use App\Models\VaultVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Database-backed contract operations for HTTP MCP / REST (perimeter first).
 */
final class IndexedContractService
{
    public function __construct(
        private readonly ContractAuditService $audit = new ContractAuditService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scopes(PerimeterData $perimeter, ApiKey $apiKey): array
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
        ApiKey $apiKey,
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
     * @return array<string, mixed>
     */
    public function search(
        PerimeterData $perimeter,
        ApiKey $apiKey,
        string $q,
        ?string $area = null,
        int $limit = 20,
    ): array {
        unset($perimeter, $apiKey, $q, $area, $limit);

        // ponytail: FTS drivers land in plan step 11; refuse clearly until then.
        throw new RuntimeException('search driver not supported');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(PerimeterData $perimeter, ApiKey $apiKey, string $id): ?array
    {
        $item = $this->findItem($perimeter, $id);
        if ($item === null) {
            $this->audit->record('instituta_get', $perimeter, $apiKey, []);

            return null;
        }

        $item->load(['sections', 'structuredTables.rows', 'source']);

        $payload = [
            ...$this->summary($item, $perimeter),
            'sections' => $item->sections
                ->sortBy('position')
                ->values()
                ->map(static fn (ItemSection $section): array => [
                    'anchor' => $section->anchor,
                    'heading' => $section->heading,
                    'level' => $section->level,
                    'position' => $section->position,
                    'body' => $section->body,
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
     * @return array<string, mixed>|null
     */
    public function getSection(
        PerimeterData $perimeter,
        ApiKey $apiKey,
        string $id,
        string $anchor,
    ): ?array {
        $item = $this->findItem($perimeter, $id);
        if ($item === null) {
            $this->audit->record('instituta_get_section', $perimeter, $apiKey, []);

            return null;
        }

        /** @var ItemSection|null $section */
        $section = $item->sections()->where('anchor', $anchor)->first();
        if ($section === null) {
            $this->audit->record('instituta_get_section', $perimeter, $apiKey, [$item->id]);

            return null;
        }

        $this->audit->record('instituta_get_section', $perimeter, $apiKey, [$item->id]);

        return [
            'id' => $item->id,
            'stable_key' => $item->stable_key,
            'anchor' => $section->anchor,
            'heading' => $section->heading,
            'level' => $section->level,
            'body' => $section->body,
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
        ApiKey $apiKey,
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
    public function outline(PerimeterData $perimeter, ApiKey $apiKey, string $id): ?array
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
            'sections' => $sections->map(static fn (ItemSection $section): array => [
                'anchor' => $section->anchor,
                'heading' => $section->heading,
                'level' => $section->level,
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
     * @return array<string, mixed>
     */
    public function resolve(
        PerimeterData $perimeter,
        ApiKey $apiKey,
        string $productId,
        ?string $area = null,
    ): array {
        $product = Product::query()->find($productId);
        if ($product === null) {
            $this->audit->record('instituta_resolve', $perimeter, $apiKey, []);

            return ['entries' => [], ...$this->freezeMeta($perimeter)];
        }

        $company = $product->company;
        if ($company === null) {
            return ['entries' => [], ...$this->freezeMeta($perimeter)];
        }

        // Caller must be able to see the product scope (or a wider covering grant).
        if (! $this->productVisibleInPerimeter($perimeter, $product, $company)) {
            $this->audit->record('instituta_resolve', $perimeter, $apiKey, []);

            return ['entries' => [], ...$this->freezeMeta($perimeter)];
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
                $resolved[(string) $item->resolution_key] = [
                    'resolution_key' => (string) $item->resolution_key,
                    'title' => $item->title,
                    'summary' => $item->summary,
                    'visibility' => $item->visibility->value,
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
        $entries = array_values($resolved);

        $this->audit->record('instituta_resolve', $perimeter, $apiKey);

        return [
            'product_id' => $product->id,
            'entries' => $entries,
            'token_estimate' => count($entries) * 40,
            ...$this->freezeMeta($perimeter),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function graph(
        PerimeterData $perimeter,
        ApiKey $apiKey,
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
            ->where(function (Builder $builder) use ($id): void {
                $builder->where('id', $id)
                    ->orWhere('stable_key', $id)
                    ->orWhere('path', $id);
            })
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
            'origin' => [
                'scope_type' => $item->product_id !== null
                    ? 'product'
                    : ($item->company_id !== null ? 'company' : 'organisation'),
                'scope_id' => $item->product_id ?? $item->company_id ?? $item->organisation_id,
                'source' => $item->source_id,
                'path' => $item->path,
                'commit' => $item->commit,
            ],
            'citation' => $this->citation($item),
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
