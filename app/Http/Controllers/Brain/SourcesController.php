<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrainSourcesService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SourcesController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainSourcesService $sources,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $principal = $this->sessions->personPrincipal($user);
        $perimeter = $principal === null ? null : $this->sessions->resolve($request, $principal);

        return Inertia::render('brain/sources', [
            'sources' => $perimeter === null ? [] : $this->sources->list($perimeter),
            'hasPerimeter' => $perimeter !== null,
        ]);
    }
}
