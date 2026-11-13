<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Services\BrainMapService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brain map: areas with coverage counts inside the session perimeter.
 */
final class MapController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainMapService $map,
    ) {}

    public function __invoke(Request $request): Response
    {
        $perimeter = $this->sessions->optionalPerimeter($request);

        return Inertia::render('brain/map', [
            'areas' => $perimeter === null ? [] : $this->map->areas($perimeter),
            'hasPerimeter' => $perimeter !== null,
        ]);
    }
}
