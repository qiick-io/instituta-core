<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Services\BrainSourcesService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Synced sources list for the session perimeter.
 */
final class SourcesController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainSourcesService $sources,
    ) {}

    public function __invoke(Request $request): Response
    {
        $perimeter = $this->sessions->optionalPerimeter($request);

        return Inertia::render('brain/sources', [
            'sources' => $perimeter === null ? [] : $this->sources->list($perimeter),
            'hasPerimeter' => $perimeter !== null,
        ]);
    }
}
