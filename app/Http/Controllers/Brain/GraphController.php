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
 * `?focus=` (an item's stable key) selects one node, as links from an item do.
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

        return Inertia::render('brain/graph', [
            ...($perimeter === null ? ['nodes' => [], 'edges' => []] : $this->graph->whole($perimeter)),
            'hasPerimeter' => $perimeter !== null,
            'focus' => $focus === '' ? null : $focus,
        ]);
    }
}
