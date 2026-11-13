<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CompanyKindEnum;
use App\Enums\OffboardingStatusEnum;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemSection;
use App\Models\OffboardingRequest;
use App\Models\Product;
use App\Models\Source;
use App\Models\StructuredRow;
use App\Models\StructuredTable;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Exports a client company subtree (case 8): sources, index JSON, manifest.
 */
final class ClientExportService
{
    /**
     * @return array{path: string, request: OffboardingRequest, manifest: array<string, mixed>}
     */
    public function export(Company $company, ?string $destination = null): array
    {
        if ($company->kind !== CompanyKindEnum::Client) {
            throw new InvalidArgumentException('Only client companies can be exported.');
        }

        $company->loadMissing('organisation');
        $productIds = Product::query()->where('company_id', $company->id)->pluck('id')->all();

        $sources = Source::query()
            ->where('organisation_id', $company->organisation_id)
            ->where(function ($query) use ($company, $productIds): void {
                $query->where('company_id', $company->id);
                if ($productIds !== []) {
                    $query->orWhereIn('product_id', $productIds);
                }
            })
            ->orderBy('name')
            ->get();

        $items = Item::query()
            ->where('organisation_id', $company->organisation_id)
            ->where(function ($query) use ($company, $productIds): void {
                $query->where('company_id', $company->id);
                if ($productIds !== []) {
                    $query->orWhereIn('product_id', $productIds);
                }
            })
            ->orderBy('path')
            ->get();

        $itemIds = $items->pluck('id')->all();

        $sections = $itemIds === []
            ? collect()
            : ItemSection::query()->whereIn('item_id', $itemIds)->orderBy('position')->get();
        $links = $itemIds === []
            ? collect()
            : ItemLink::query()->whereIn('from_item_id', $itemIds)->orderBy('target')->get();
        $tables = $itemIds === []
            ? collect()
            : StructuredTable::query()->whereIn('item_id', $itemIds)->orderBy('position')->get();
        $tableIds = $tables->pluck('id')->all();
        $rows = $tableIds === []
            ? collect()
            : StructuredRow::query()->whereIn('structured_table_id', $tableIds)->orderBy('position')->get();

        $stamp = now()->format('YmdHis');
        $root = $destination ?? storage_path('instituta/exports/'.$company->slug.'-'.$stamp);
        File::ensureDirectoryExists($root);

        $manifest = [
            'format' => 'instituta.export.v1',
            'exported_at' => now()->toIso8601String(),
            'organisation' => [
                'id' => $company->organisation_id,
                'slug' => $company->organisation?->slug,
                'name' => $company->organisation?->name,
            ],
            'company' => [
                'id' => $company->id,
                'slug' => $company->slug,
                'name' => $company->name,
                'kind' => $company->kind->value,
            ],
            'products' => Product::query()
                ->where('company_id', $company->id)
                ->orderBy('slug')
                ->get(['id', 'slug', 'name', 'inherits_from_parent'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'inherits_from_parent' => $product->inherits_from_parent,
                ])
                ->values()
                ->all(),
            'sources' => $sources->map(fn (Source $source): array => [
                'id' => $source->id,
                'name' => $source->name,
                'provider' => $source->provider->value,
                'last_synced_commit' => $source->last_synced_commit,
                'company_id' => $source->company_id,
                'product_id' => $source->product_id,
            ])->values()->all(),
            'counts' => [
                'items' => $items->count(),
                'sections' => $sections->count(),
                'links' => $links->count(),
                'tables' => $tables->count(),
                'rows' => $rows->count(),
            ],
        ];

        $index = [
            'items' => $items->map(fn (Item $item): array => [
                'id' => $item->id,
                'organisation_id' => $item->organisation_id,
                'company_id' => $item->company_id,
                'product_id' => $item->product_id,
                'source_id' => $item->source_id,
                'path' => $item->path,
                'stable_key' => $item->stable_key,
                'type' => $item->type,
                'area' => $item->area,
                'title' => $item->title,
                'summary' => $item->summary,
                'visibility' => $item->visibility->value,
                'commit' => $item->commit,
                'content_hash' => $item->content_hash,
                'frontmatter' => $item->frontmatter,
                'decision_date' => $item->decision_date?->toDateString(),
                'effective_date' => $item->effective_date?->toDateString(),
                'review_date' => $item->review_date?->toDateString(),
                'resolution_key' => $item->resolution_key,
            ])->values()->all(),
            'sections' => $sections->map(fn (ItemSection $section): array => $section->only([
                'id', 'item_id', 'organisation_id', 'company_id', 'product_id',
                'anchor', 'heading', 'level', 'position', 'body', 'blocks',
            ]))->values()->all(),
            'links' => $links->map(fn (ItemLink $link): array => $link->only([
                'id', 'from_item_id', 'to_item_id', 'organisation_id', 'company_id',
                'product_id', 'target', 'relation',
            ]))->values()->all(),
            'tables' => $tables->map(fn (StructuredTable $table): array => $table->only([
                'id', 'item_id', 'organisation_id', 'company_id', 'product_id',
                'table_key', 'columns', 'position',
            ]))->values()->all(),
            'rows' => $rows->map(fn (StructuredRow $row): array => $row->only([
                'id', 'structured_table_id', 'item_id', 'organisation_id', 'company_id',
                'product_id', 'values', 'position',
            ]))->values()->all(),
        ];

        $manifestPath = $root.'/manifest.json';
        $indexPath = $root.'/index.json';

        if (File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") === false) {
            throw new RuntimeException('Failed to write manifest.json');
        }
        if (File::put($indexPath, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") === false) {
            throw new RuntimeException('Failed to write index.json');
        }

        $request = OffboardingRequest::query()->create([
            'company_id' => $company->id,
            'status' => OffboardingStatusEnum::Exported,
            'export_path' => $root,
            'requested_at' => now(),
        ]);

        app(ActivityEventLoggerService::class)->exportCompleted($company, $request);

        return [
            'path' => $root,
            'request' => $request,
            'manifest' => $manifest,
        ];
    }
}
