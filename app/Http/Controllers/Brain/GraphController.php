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
 * Scoped neighbour graph for one brain item (#49).
 */
final class GraphController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainGraphService $graph,
    ) {}

    public function __invoke(Request $request, ?string $item = null): Response
    {
        $perimeter = $this->sessions->optionalPerimeter($request);

        $depth = (int) $request->integer('depth', 1);
        $hasExplicitItem = is_string($item) && $item !== '';
        $payload = $perimeter === null
            ? ['root' => null, 'nodes' => [], 'edges' => [], 'depth' => max(1, min(2, $depth))]
            : $this->graph->forItem($perimeter, $item, $depth);

        // Match reading-view behaviour: an explicit out-of-perimeter id is not found.
        if ($hasExplicitItem && ($payload['root']['id'] ?? null) !== $item) {
            abort(404);
        }

        return Inertia::render('brain/graph', [
            'hasPerimeter' => $perimeter !== null,
            'root' => $payload['root'],
            'nodes' => $payload['nodes'],
            'edges' => $payload['edges'],
            'depth' => $payload['depth'],
            'itemId' => $payload['root']['id'] ?? $item,
        ]);
    }
}
