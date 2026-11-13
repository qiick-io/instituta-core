<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Models\Item;

/**
 * Tree + list of brain items inside the session perimeter, grouped by area and path.
 */
final class BrainTreeService
{
    /**
     * @return array{
     *     groups: list<array{area: string, items: list<array{id: string, title: string, path: string, stable_key: string, type: string, summary: string|null}>}>,
     *     area: string|null,
     *     total: int
     * }
     */
    public function tree(PerimeterData $perimeter, ?string $area = null): array
    {
        $query = Item::query()
            ->withinPerimeter($perimeter)
            ->orderBy('area')
            ->orderBy('path');

        if ($area !== null && $area !== '') {
            $query->where('area', $area);
        }

        /** @var list<Item> $items */
        $items = $query->get([
            'id',
            'area',
            'title',
            'path',
            'stable_key',
            'type',
            'summary',
        ])->all();

        $buckets = [];
        foreach ($items as $item) {
            $key = $item->area;
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'area' => $key,
                    'items' => [],
                ];
            }

            $buckets[$key]['items'][] = [
                'id' => $item->id,
                'title' => $item->title,
                'path' => $item->path,
                'stable_key' => $item->stable_key,
                'type' => $item->type,
                'summary' => $item->summary,
            ];
        }

        return [
            'groups' => array_values($buckets),
            'area' => $area !== null && $area !== '' ? $area : null,
            'total' => count($items),
        ];
    }
}
