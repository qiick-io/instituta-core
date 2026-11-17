<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Services\BrainGraphService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The whole brain as a graph (#254): nodes and links of the perimeter, laid out in the browser.
 * The view lives in the query string (#279), so a link reopens it as it was: `?focus=` (an item's
 * stable key) selects one node, `?to=` traces the path from it to another, `?area=` (comma list)
 * shows only those areas and `?layout=constellations` groups the nodes by area.
 */
final class GraphController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainGraphService $graph,
    ) {}

    public function __invoke(Request $request): Response
    {
        $perimeter = $this->sessions->optionalPerimeter($request);
        $focus = $request->string('focus')->toString();
        $to = $request->string('to')->toString();
        $areas = $request->string('area')->explode(',')
            ->map(static fn (string $area): string => trim($area))
            ->filter(static fn (string $area): bool => $area !== '')
            ->unique()
            ->values()
            ->all();

        return Inertia::render('brain/graph', [
            ...($perimeter === null ? ['nodes' => [], 'edges' => []] : $this->graph->whole($perimeter)),
            'hasPerimeter' => $perimeter !== null,
            'focus' => $focus === '' ? null : $focus,
            'view' => [
                'areas' => $areas,
                'layout' => $request->string('layout')->toString() === 'constellations' ? 'constellations' : 'free',
                'to' => $to === '' ? null : $to,
            ],
        ]);
    }
}
