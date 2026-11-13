<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Http\Requests\Brain\IndexSessionReplayRequest;
use App\Http\Resources\ContractCallReplayResource;
use App\Models\User;
use App\Services\ContractCallReplayQueryService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organisation-owner session replay over contract_calls.
 */
final class SessionReplayController extends Controller
{
    public function __construct(
        private readonly ContractCallReplayQueryService $replay,
    ) {}

    public function __invoke(IndexSessionReplayRequest $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $organisationId = $this->replay->organisationIdForOwner($user);
        $filters = $this->replay->resolvedFilters($request);
        $emptyPayload = [
            'is_owner' => false,
            'calls' => [
                'data' => [],
                'links' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => IndexSessionReplayRequest::LIST_DEFAULT_PER_PAGE,
                    'total' => 0,
                    'from' => null,
                    'to' => null,
                ],
            ],
            'principals' => [],
            'tools' => [],
            'filters' => $filters,
        ];

        if ($organisationId === null) {
            return Inertia::render('brain/session-replay', $emptyPayload);
        }

        $paginator = $this->replay
            ->paginate($organisationId, $filters, $request->perPage())
            ->withQueryString();

        return Inertia::render('brain/session-replay', [
            'is_owner' => true,
            'calls' => ContractCallReplayResource::collection($paginator),
            'principals' => $this->replay->principalsForOrganisation($organisationId),
            'tools' => $this->replay->toolsForOrganisation($organisationId),
            'filters' => $filters,
        ]);
    }
}
