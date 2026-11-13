<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLogResource;
use App\Models\User;
use App\Services\ActivityLogQueryService;
use App\Services\FilterableActivityEventsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organisation-owner Activity (audit) log browser.
 */
final class ActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityLogQueryService $activityLogs,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $organisationId = $this->activityLogs->organisationIdForOwner($user);

        if ($organisationId === null) {
            return Inertia::render('activity/index', [
                'is_owner' => false,
                'activityLogs' => [
                    'data' => [],
                    'links' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 20,
                        'total' => 0,
                        'from' => null,
                        'to' => null,
                    ],
                ],
                'causers' => [],
                'events' => FilterableActivityEventsService::all(),
                'logNames' => $this->activityLogs->logNames(),
                'filters' => $this->activityLogs->resolvedFilters($request),
                'retention_days' => (int) config('activitylog.clean_after_days', 365),
            ]);
        }

        $filters = $this->activityLogs->resolvedFilters($request);
        $perPage = max(10, min(100, $request->integer('per_page', 20) ?: 20));

        $paginator = $this->activityLogs
            ->paginate($organisationId, $filters, $perPage)
            ->withQueryString();

        return Inertia::render('activity/index', [
            'is_owner' => true,
            'activityLogs' => ActivityLogResource::collection($paginator),
            'causers' => $this->activityLogs->causersForOrganisation($organisationId),
            'events' => FilterableActivityEventsService::all(),
            'logNames' => $this->activityLogs->logNames(),
            'filters' => $filters,
            'retention_days' => (int) config('activitylog.clean_after_days', 365),
        ]);
    }
}
