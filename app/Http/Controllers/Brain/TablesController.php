<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrainTablesService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TablesController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainTablesService $tables,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $principal = $this->sessions->personPrincipal($user);
        $perimeter = $principal === null ? null : $this->sessions->resolve($request, $principal);

        return Inertia::render('brain/tables/index', [
            'tables' => $perimeter === null ? [] : $this->tables->list($perimeter),
            'hasPerimeter' => $perimeter !== null,
        ]);
    }

    public function show(Request $request, string $item, string $table): Response
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

        $payload = $this->tables->show($perimeter, $item, $table);
        if ($payload === null) {
            abort(404);
        }

        return Inertia::render('brain/tables/show', [
            'table' => $payload,
        ]);
    }
}
