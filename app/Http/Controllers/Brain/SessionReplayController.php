<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractCallReplayResource;
use App\Models\ContractCall;
use App\Models\Principal;
use App\Models\User;
use App\Services\ContractCallReplayQueryService;
use Illuminate\Http\Request;
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

    public function __invoke(Request $request): Response
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
                    'per_page' => 20,
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

        $perPage = max(10, min(100, $request->integer('per_page', 20) ?: 20));
        $paginator = $this->replay
            ->paginate($organisationId, $filters, $perPage)
            ->withQueryString();

        $principals = Principal::query()
            ->where('organisation_id', $organisationId)
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name'])
            ->map(fn (Principal $principal): array => [
                'id' => $principal->id,
                'name' => $principal->name,
            ])
            ->values()
            ->all();

        $tools = ContractCall::query()
            ->where('organisation_id', $organisationId)
            ->distinct()
            ->orderBy('tool_or_endpoint')
            ->limit(100)
            ->pluck('tool_or_endpoint')
            ->all();

        return Inertia::render('brain/session-replay', [
            'is_owner' => true,
            'calls' => ContractCallReplayResource::collection($paginator),
            'principals' => $principals,
            'tools' => $tools,
            'filters' => $filters,
        ]);
    }
}
