<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Brain\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Services\BrainReadingService;
use App\Services\BrainTreeService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The brain as files (#253): the file tree of the perimeter and, when one is open, the item's
 * reading view next to it. Replaces the Map, Tree and Reading pages.
 */
final class ExplorerController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainTreeService $tree,
        private readonly BrainReadingService $reading,
    ) {}

    public function index(Request $request): Response
    {
        $perimeter = $this->sessions->optionalPerimeter($request);

        return Inertia::render('brain/explorer', [
            ...($perimeter === null ? ['nodes' => [], 'total' => 0] : $this->tree->tree($perimeter)),
            'item' => null,
            'hasPerimeter' => $perimeter !== null,
            'canPropose' => $perimeter !== null && $perimeter->role !== RoleEnum::Guest,
        ]);
    }

    /**
     * One item by id, stable key or path; 404 outside the perimeter.
     */
    public function show(Request $request, string $item): Response
    {
        $perimeter = $this->sessions->requirePerimeter($request);

        Gate::authorize('viewAny', Item::class);

        $payload = $this->reading->item($perimeter, $item);
        abort_if($payload === null, 404);

        $itemModel = Item::query()->find($payload['id']);

        if ($itemModel instanceof Item) {
            Gate::authorize('view', $itemModel);
        }

        return Inertia::render('brain/explorer', [
            ...$this->tree->tree($perimeter),
            'item' => $payload,
            'hasPerimeter' => true,
            'canPropose' => $perimeter->role !== RoleEnum::Guest,
        ]);
    }
}
