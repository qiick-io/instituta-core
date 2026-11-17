<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
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
        $urlsByLinkTarget = $this->urlsByLinkTarget($item);

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
     * @return array{id: string, summary: string|null, sections: array<int, array{anchor: string, heading: string, level: int, html: string, body: string}>, hasMore: bool}|null
     */
    public function preview(PerimeterData $perimeter, string $id, int $sections = 4): ?array
    {
        $item = $this->find($perimeter, $id);

        if ($item === null) {
            return null;
        }

        $urlsByLinkTarget = $this->urlsByLinkTarget($item);
        $all = $item->sections()->orderBy('position')->get();

        return [
            'id' => $item->id,
            'summary' => $item->summary,
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
            ->where(static function ($query) use ($id): void {
                $query->where('id', $id)
                    ->orWhere('stable_key', $id)
                    ->orWhere('path', $id);
            })
            ->first();

        return $item;
    }

    /**
     * Links the sync resolved open the target's reading view (#241).
     *
     * @return array<string, string>
     */
    private function urlsByLinkTarget(Item $item): array
    {
        return ItemLink::query()
            ->where('from_item_id', $item->id)
            ->whereNotNull('to_item_id')
            ->get(['target', 'to_item_id'])
            ->mapWithKeys(static fn (ItemLink $link): array => [$link->target => route('explorer.show', (string) $link->to_item_id, absolute: false)])
            ->all();
    }
}
