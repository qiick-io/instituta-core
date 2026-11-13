<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Models\Item;

/**
 * Session graph payload for the scoped brain graph UI (#49).
 * Reuses IndexedContractService neighbours (depth 1–2, perimeter filtered).
 */
final class BrainGraphService
{
    public function __construct(
        private readonly IndexedContractService $contract = new IndexedContractService,
    ) {}

    /**
     * @return array{root: array<string, mixed>|null, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, depth: int}
     */
    public function forItem(PerimeterData $perimeter, ?string $itemId, int $depth = 1): array
    {
        $depth = max(1, min(2, $depth));

        if ($itemId === null || $itemId === '') {
            $fallback = Item::query()
                ->withinPerimeter($perimeter)
                ->orderBy('title')
                ->first();

            if ($fallback === null) {
                return [
                    'root' => null,
                    'nodes' => [],
                    'edges' => [],
                    'depth' => $depth,
                ];
            }

            $itemId = $fallback->id;
        }

        $graph = $this->contract->graph($perimeter, null, $itemId, $depth);
        $root = null;

        foreach ($graph['nodes'] as $node) {
            if (($node['id'] ?? null) === $itemId) {
                $root = $node;
                break;
            }
        }

        return [
            'root' => $root,
            'nodes' => array_values($graph['nodes']),
            'edges' => array_values($graph['edges']),
            'depth' => $depth,
        ];
    }
}
