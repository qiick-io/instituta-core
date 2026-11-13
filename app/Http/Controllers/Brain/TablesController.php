<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Services\BrainTablesService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Structured tables list and detail inside the session perimeter.
 */
final class TablesController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainTablesService $tables,
    ) {}

    public function index(Request $request): Response
    {
        $perimeter = $this->sessions->optionalPerimeter($request);

        return Inertia::render('brain/tables/index', [
            'tables' => $perimeter === null ? [] : $this->tables->list($perimeter),
            'hasPerimeter' => $perimeter !== null,
        ]);
    }

    public function show(Request $request, string $item, string $table): Response
    {
        $perimeter = $this->sessions->requirePerimeter($request);

        $payload = $this->tables->show($perimeter, $item, $table);
        if ($payload === null) {
            abort(404);
        }

        return Inertia::render('brain/tables/show', [
            'table' => $payload,
        ]);
    }
}
