<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Models\Item;
use App\Models\ItemLink;

/**
 * The brain graph of the web UI (#254). MCP and REST keep their neighbours-only graph
 * (`instituta_graph`), which answers one item at a time.
 */
final class BrainGraphService
{
    /**
     * The whole brain in the perimeter as a graph (#254): every item a node with its number of
     * links, every resolved link between two items in the perimeter an edge, once per pair.
     * Unresolved links are left out (the health check lists them).
     *
     * @return array{nodes: list<array{id: string, title: string, stable_key: string, area: string, status: string, degree: int}>, edges: list<array{from: string, to: string}>}
     */
    public function whole(PerimeterData $perimeter): array
    {
        $items = Item::query()
            ->withinPerimeter($perimeter)
            ->get(['id', 'title', 'stable_key', 'area', 'status'])
            ->keyBy('id');

        $edges = [];
        $degrees = [];

        foreach (ItemLink::query()->whereIn('from_item_id', $items->keys()->all())->whereNotNull('to_item_id')->get(['from_item_id', 'to_item_id']) as $link) {
            $from = (string) $link->from_item_id;
            $to = (string) $link->to_item_id;

            if ($from === $to || ! $items->has($to)) {
                continue;
            }

            $pairKey = strcmp($from, $to) < 0 ? $from.'|'.$to : $to.'|'.$from;

            if (isset($edges[$pairKey])) {
                continue;
            }

            $edges[$pairKey] = ['from' => $from, 'to' => $to];
            $degrees[$from] = ($degrees[$from] ?? 0) + 1;
            $degrees[$to] = ($degrees[$to] ?? 0) + 1;
        }

        return [
            'nodes' => array_values($items->map(static fn (Item $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'stable_key' => $item->stable_key,
                'area' => $item->area,
                'status' => $item->status->value,
                'degree' => $degrees[$item->id] ?? 0,
            ])->all()),
            'edges' => array_values($edges),
        ];
    }
}
