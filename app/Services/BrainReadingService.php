<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\StructuredTable;

/**
 * Human reading payload for one brain item inside the session perimeter.
 */
final class BrainReadingService
{
    /**
     * @return array<string, mixed>|null
     */
    public function item(PerimeterData $perimeter, string $id): ?array
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

        if ($item === null) {
            return null;
        }

        $item->load(['sections', 'structuredTables.rows', 'source']);

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
        ];
    }
}
