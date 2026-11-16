<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Brain\Enums\RoleEnum;
use App\Models\User;
use App\Services\BrainHealthService;
use App\Services\Dashboard\DashboardOverviewMetricsService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first screen after sign-in: key numbers and short lists for the session perimeter.
 *
 * Copied from externa-core `DashboardController` (one view, no tabs yet). Like the brain
 * screens it is gated by the perimeter, not by a permission: a user without a perimeter
 * gets the empty state.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly DashboardOverviewMetricsService $metrics,
        private readonly BrainHealthService $health,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $perimeter = $this->sessions->optionalPerimeter($request);

        if ($perimeter === null) {
            return Inertia::render('dashboard', ['hasPerimeter' => false]);
        }

        return Inertia::render('dashboard', [
            'hasPerimeter' => true,
            'contentStats' => $this->metrics->contentStats($perimeter),
            'areaCounts' => $this->metrics->areaCounts($perimeter),
            'sources' => $this->metrics->sources($perimeter),
            'proposals' => $this->metrics->openProposals($perimeter),
            'apiKeys' => $this->metrics->apiKeys($user, now()),
            'latestActivity' => $this->metrics->latestActivity($user),
            // Owners tend the brain, so they see its health (#231).
            'health' => $perimeter->role === RoleEnum::Owner ? $this->health->check($perimeter) : null,
        ]);
    }
}
