<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrainMapService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MapController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainMapService $map,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $principal = $this->sessions->personPrincipal($user);
        $perimeter = $principal === null ? null : $this->sessions->resolve($request, $principal);

        return Inertia::render('brain/map', [
            'areas' => $perimeter === null ? [] : $this->map->areas($perimeter),
            'hasPerimeter' => $perimeter !== null,
        ]);
    }
}
