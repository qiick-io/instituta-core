<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $principal = $this->sessions->personPrincipal($user);
        $perimeter = $principal === null ? null : $this->sessions->resolve($request, $principal);

        $depth = (int) $request->integer('depth', 1);
        $payload = $perimeter === null
            ? ['root' => null, 'nodes' => [], 'edges' => [], 'depth' => max(1, min(2, $depth))]
            : $this->graph->forItem($perimeter, $item, $depth);

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
