<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Readers\AssetReader;
use App\Brain\Support\PathHelper;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemSection;
use App\Models\StructuredTable;

/**
 * Human reading payload for one brain item inside the session perimeter.
 */
final class BrainReadingService
{
    public function __construct(
        private readonly SectionHtmlRendererService $sectionHtml = new SectionHtmlRendererService,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function item(PerimeterData $perimeter, string $id): ?array
    {
        $item = $this->find($perimeter, $id);

        if ($item === null) {
            return null;
        }

        $item->load(['sections', 'structuredTables.rows', 'source']);
        $urlsByLinkTarget = $this->urlsByLinkTarget($perimeter, $item);

        return [
            'id' => $item->id,
            'stable_key' => $item->stable_key,
            'title' => $item->title,
            'summary' => $item->summary,
            'area' => $item->area,
            'type' => $item->type,
            'visibility' => $item->visibility->value,
            'path' => $item->path,
            'commit' => $item->commit,
            'content_hash' => $item->content_hash,
            'citation' => [
                'source' => $item->source !== null && $item->source->name !== ''
                    ? $item->source->name
                    : $item->source_id,
                'source_id' => $item->source_id,
                'path' => $item->path,
                'commit' => $item->commit,
            ],
            'asset' => $this->assetPayload($perimeter, $item),
            'generated_blocks' => array_values(array_unique(
                $item->sections
                    ->sortBy('position')
                    ->flatMap(static fn (ItemSection $section): array => SectionHtmlRendererService::generatedBlocks((string) $section->markdown))
                    ->all(),
                SORT_REGULAR,
            )),
            'sections' => $item->sections
                ->sortBy('position')
                ->values()
                ->map(fn (ItemSection $section): array => [
                    'anchor' => $section->anchor,
                    'heading' => $section->heading,
                    'level' => $section->level,
                    'position' => $section->position,
                    'body' => $section->body,
                    'markdown' => $section->markdown,
                    'html' => $this->sectionHtml->render($section->markdown, $urlsByLinkTarget),
                    'section_hash' => ItemSection::hashOf($section->markdown),
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
        ];
    }

    /**
     * A quick look at one item for the graph's side card (#273): its summary and first sections,
     * rendered like the reading view. Null outside the perimeter.
     *
     * @return array{id: string, summary: string|null, asset: array{url: string, mime: string, alt: string|null}|null, sections: array<int, array{anchor: string, heading: string, level: int, html: string, body: string}>, hasMore: bool}|null
     */
    public function preview(PerimeterData $perimeter, string $id, int $sections = 4): ?array
    {
        $item = $this->find($perimeter, $id);

        if ($item === null) {
            return null;
        }

        $urlsByLinkTarget = $this->urlsByLinkTarget($perimeter, $item);
        $all = $item->sections()->orderBy('position')->get();

        $asset = self::isAsset($item) && is_array($item->frontmatter['asset'] ?? null) ? $item->frontmatter['asset'] : null;

        return [
            'id' => $item->id,
            'summary' => $item->summary,
            // An image or PDF previews as itself (#311).
            'asset' => $asset === null ? null : [
                'url' => route('brain.assets.show', $item->id, absolute: false),
                'mime' => (string) AssetReader::mimeType($item->path),
                'alt' => is_string($asset['alt'] ?? null) ? $asset['alt'] : null,
            ],
            'sections' => $all
                ->take($sections)
                ->values()
                ->map(fn (ItemSection $section): array => [
                    'anchor' => $section->anchor,
                    'heading' => $section->heading,
                    'level' => $section->level,
                    'html' => $this->sectionHtml->render($section->markdown, $urlsByLinkTarget),
                    'body' => $section->body,
                ])
                ->all(),
            'hasMore' => $all->count() > $sections,
        ];
    }

    /**
     * One item by id, stable key or path, inside the perimeter.
     */
    private function find(PerimeterData $perimeter, string $id): ?Item
    {
        /** @var Item|null $item */
        $item = Item::query()
            ->withinPerimeter($perimeter)
            ->matchingReference($id)
            ->first();

        return $item;
    }

    /**
     * Links the sync resolved open the target's reading view (#241). Only targets inside the
     * perimeter: a link to an item outside it (another area, #334) stays plain text, so the page
     * never carries that item's id.
     *
     * @return array<string, string>
     */
    private function urlsByLinkTarget(PerimeterData $perimeter, Item $item): array
    {
        $links = ItemLink::query()
            ->where('from_item_id', $item->id)
            ->whereIn('to_item_id', Item::query()->withinPerimeter($perimeter)->select('id'))
            ->get(['target', 'to_item_id']);
        // A link or picture pointing at an asset opens the file itself (#311).
        $assetIds = Item::query()
            ->whereKey($links->pluck('to_item_id')->unique()->all())
            ->get(['id', 'path', 'frontmatter'])
            ->filter(static fn (Item $target): bool => self::isAsset($target))
            ->pluck('id')
            ->all();

        return $links
            ->mapWithKeys(static fn (ItemLink $link): array => [
                $link->target => in_array($link->to_item_id, $assetIds, true)
                    ? route('brain.assets.show', (string) $link->to_item_id, absolute: false)
                    : route('explorer.show', (string) $link->to_item_id, absolute: false),
            ])
            ->all();
    }

    /**
     * Whether an item is a brain asset (an image or a PDF, #311): it carries the asset metadata the
     * reader records and a served extension.
     */
    public static function isAsset(Item $item): bool
    {
        return is_array($item->frontmatter['asset'] ?? null) && AssetReader::mimeType($item->path) !== null;
    }

    /**
     * An asset's metadata for the reading view, and where it is used (#311); null for a document.
     *
     * @return array{mime: string, kind: string, extension: string, is_download_only: bool, bytes: int, width: int|null, height: int|null, alt: string|null, caption: string|null, url: string, download_url: string, used_in: list<array{title: string, stable_key: string}>}|null
     */
    private function assetPayload(PerimeterData $perimeter, Item $item): ?array
    {
        $asset = $item->frontmatter['asset'] ?? null;

        if (! is_array($asset) || ! self::isAsset($item)) {
            return null;
        }

        /** @var list<array{title: string, stable_key: string}> $usedIn */
        $usedIn = Item::query()
            ->withinPerimeter($perimeter)
            ->whereIn('id', ItemLink::query()->where('to_item_id', $item->id)->select('from_item_id'))
            ->orderBy('title')
            ->limit(50)
            ->get(['title', 'stable_key'])
            ->map(static fn (Item $user): array => ['title' => $user->title, 'stable_key' => $user->stable_key])
            ->values()
            ->all();

        return [
            'mime' => (string) AssetReader::mimeType($item->path),
            'kind' => is_string($asset['kind'] ?? null) ? $asset['kind'] : 'image',
            'extension' => PathHelper::extension($item->path),
            'is_download_only' => AssetReader::isDownloadOnly($item->path),
            'bytes' => is_int($asset['bytes'] ?? null) ? $asset['bytes'] : 0,
            'width' => is_int($asset['width'] ?? null) ? $asset['width'] : null,
            'height' => is_int($asset['height'] ?? null) ? $asset['height'] : null,
            'alt' => is_string($asset['alt'] ?? null) ? $asset['alt'] : null,
            'caption' => is_string($asset['caption'] ?? null) ? $asset['caption'] : null,
            'url' => route('brain.assets.show', $item->id, absolute: false),
            'download_url' => route('brain.assets.show', ['item' => $item->id, 'download' => 1], absolute: false),
            'used_in' => $usedIn,
        ];
    }
}
