<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrainReadingService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ReadingController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainReadingService $reading,
    ) {}

    public function __invoke(Request $request, string $item): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $principal = $this->sessions->personPrincipal($user);
        if ($principal === null) {
            abort(404);
        }

        $perimeter = $this->sessions->resolve($request, $principal);
        if ($perimeter === null) {
            abort(404);
        }

        $payload = $this->reading->item($perimeter, $item);
        if ($payload === null) {
            abort(404);
        }

        return Inertia::render('brain/reading', [
            'item' => $payload,
        ]);
    }
}
