<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * Area coverage map for the human UI (counts inside the session perimeter).
 */
final class BrainMapService
{
    /**
     * Canonical areas from docs/FORMAT.md — empty ones still appear as gaps.
     *
     * @var list<string>
     */
    public const AREAS = [
        'rules',
        'decisions',
        'style',
        'glossary',
        'design-system',
        'plans',
        'connected-systems',
        'notes',
    ];

    /**
     * @return list<array{area: string, count: int, empty: bool}>
     */
    public function areas(PerimeterData $perimeter): array
    {
        $counts = Item::query()
            ->withinPerimeter($perimeter)
            ->select('area', DB::raw('count(*) as aggregate'))
            ->groupBy('area')
            ->pluck('aggregate', 'area');

        $rows = [];
        foreach (self::AREAS as $area) {
            $count = (int) ($counts[$area] ?? 0);
            $rows[] = [
                'area' => $area,
                'count' => $count,
                'empty' => $count === 0,
            ];
        }

        // Unknown areas still show so nothing is hidden.
        foreach ($counts as $area => $aggregate) {
            if (! is_string($area) || in_array($area, self::AREAS, true)) {
                continue;
            }
            $rows[] = [
                'area' => $area,
                'count' => (int) $aggregate,
                'empty' => false,
            ];
        }

        return $rows;
    }
}
