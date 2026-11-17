<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Services\BrainReadingService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A quick look at one item from the graph (#273): its summary and first sections as the server
 * renders them, so the graph's side card can show the content without leaving the page. Same
 * perimeter and policy checks as the explorer; 404 outside the perimeter.
 */
final class GraphPreviewController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainReadingService $reading,
    ) {}

    public function __invoke(Request $request, string $item): JsonResponse
    {
        $perimeter = $this->sessions->requirePerimeter($request);

        Gate::authorize('viewAny', Item::class);

        $preview = $this->reading->preview($perimeter, $item);
        abort_if($preview === null, 404);

        $itemModel = Item::query()->find($preview['id']);

        if ($itemModel instanceof Item) {
            Gate::authorize('view', $itemModel);
        }

        unset($preview['id']);

        return response()->json($preview);
    }
}
