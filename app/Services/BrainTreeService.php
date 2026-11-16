<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Models\Item;
use App\Models\Source;

/**
 * The explorer's file tree (#253): the brain items in the session perimeter as their
 * repositories lay them out, repository › folders › files, folders before files and both by
 * name. Built in one query; a brain has hundreds of items, not hundreds of thousands.
 */
final class BrainTreeService
{
    /**
     * @return array{nodes: list<array<string, mixed>>, total: int}
     */
    public function tree(PerimeterData $perimeter): array
    {
        /** @var list<Item> $items */
        $items = Item::query()
            ->withinPerimeter($perimeter)
            ->orderBy('path')
            ->get(['id', 'source_id', 'title', 'path', 'stable_key', 'status'])
            ->all();

        $sourceNames = Source::query()
            ->whereIn('id', array_values(array_unique(array_map(static fn (Item $item): string => $item->source_id, $items))))
            ->pluck('name', 'id')
            ->all();

        /** @var array<string, array<string, mixed>> $roots */
        $roots = [];

        foreach ($items as $item) {
            $sourceName = (string) ($sourceNames[$item->source_id] ?? $item->source_id);
            $roots[$item->source_id] ??= ['kind' => 'folder', 'name' => $sourceName, 'path' => $sourceName, 'children' => []];

            $segments = explode('/', $item->path);
            $fileName = array_pop($segments);
            $folder = &$roots[$item->source_id];
            $folderPath = $sourceName;

            foreach ($segments as $segment) {
                $folderPath .= '/'.$segment;
                $folder['children'][$segment] ??= ['kind' => 'folder', 'name' => $segment, 'path' => $folderPath, 'children' => []];
                $folder = &$folder['children'][$segment];
            }

            $folder['children']["\0".$fileName] = [
                'kind' => 'file',
                'id' => $item->id,
                'name' => $item->title !== '' ? $item->title : $fileName,
                'path' => $item->path,
                'stable_key' => $item->stable_key,
                'status' => $item->status->value,
            ];
            unset($folder);
        }

        return [
            'nodes' => array_map($this->sorted(...), array_values($roots)),
            'total' => count($items),
        ];
    }

    /**
     * Folders first, then files, each by name; children become a list.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function sorted(array $node): array
    {
        if ($node['kind'] !== 'folder') {
            return $node;
        }

        /** @var array<string, array<string, mixed>> $children */
        $children = $node['children'];
        $children = array_map($this->sorted(...), array_values($children));

        usort($children, static fn (array $left, array $right): int => [$left['kind'] === 'file', mb_strtolower((string) $left['name'])]
            <=> [$right['kind'] === 'file', mb_strtolower((string) $right['name'])]);

        $node['children'] = $children;

        return $node;
    }
}
