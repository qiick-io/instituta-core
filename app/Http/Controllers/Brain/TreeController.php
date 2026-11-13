<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Services\BrainTreeService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hierarchical tree of brain items in the session perimeter.
 */
final class TreeController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainTreeService $tree,
    ) {}

    public function __invoke(Request $request): Response
    {
        $area = $request->string('area')->toString();
        $area = $area === '' ? null : $area;

        $perimeter = $this->sessions->optionalPerimeter($request);

        $payload = $perimeter === null
            ? ['groups' => [], 'area' => $area, 'total' => 0]
            : $this->tree->tree($perimeter, $area);

        return Inertia::render('brain/tree', [
            ...$payload,
            'hasPerimeter' => $perimeter !== null,
        ]);
    }
}
