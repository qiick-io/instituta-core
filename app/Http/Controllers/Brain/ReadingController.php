<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Services\BrainReadingService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Progressive reading view for a single brain item in the session perimeter.
 */
final class ReadingController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainReadingService $reading,
    ) {}

    public function __invoke(Request $request, string $item): Response
    {
        $perimeter = $this->sessions->requirePerimeter($request);

        Gate::authorize('viewAny', Item::class);

        $payload = $this->reading->item($perimeter, $item);
        if ($payload === null) {
            abort(404);
        }

        $itemModel = Item::query()->find($item);

        if ($itemModel instanceof Item) {
            Gate::authorize('view', $itemModel);
        }

        return Inertia::render('brain/reading', [
            'item' => $payload,
        ]);
    }
}
