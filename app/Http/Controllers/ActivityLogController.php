<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexActivityLogRequest;
use App\Http\Resources\ActivityLogResource;
use App\Models\User;
use App\Services\ActivityLogQueryService;
use App\Services\FilterableActivityEventsService;
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

    public function __invoke(IndexActivityLogRequest $request): Response
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
                        'per_page' => IndexActivityLogRequest::LIST_DEFAULT_PER_PAGE,
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

        $paginator = $this->activityLogs
            ->paginate($organisationId, $filters, $request->perPage())
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
