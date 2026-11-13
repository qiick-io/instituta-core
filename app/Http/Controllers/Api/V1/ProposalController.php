<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ProposeBrainChangeAction;
use App\Actions\UpdateProposalStatusAction;
use App\Data\ProposeBrainChangeData;
use App\Data\UpdateProposalStatusData;
use App\Enums\ProposalActionEnum;
use App\Enums\ProposalStatusEnum;
use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListProposalsRequest;
use App\Http\Requests\Api\ProposeBrainChangeRequest;
use App\Http\Requests\Api\UpdateProposalStatusRequest;
use App\Services\ProposalQueryService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * REST write contract surface for brain proposals (#54).
 */
final class ProposalController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(
        private readonly ProposeBrainChangeAction $propose,
        private readonly UpdateProposalStatusAction $updateStatus,
        private readonly ProposalQueryService $queries,
    ) {}

    public function index(ListProposalsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $status = isset($validated['status'])
            ? ProposalStatusEnum::from($validated['status'])
            : null;

        return response()->json(
            $this->queries->list(
                $this->perimeter($request),
                $this->apiKey($request),
                $status,
                'api',
            ),
        );
    }

    public function store(ProposeBrainChangeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $proposal = $this->propose->execute(
            $this->perimeter($request),
            $this->apiKey($request),
            new ProposeBrainChangeData(
                action: ProposalActionEnum::from($validated['action']),
                stableKey: $validated['stable_key'],
                title: $validated['title'],
                path: $validated['path'],
                area: $validated['area'] ?? null,
                body: $validated['body'] ?? null,
                sourceId: $validated['source_id'] ?? null,
            ),
            'api',
        );

        return response()->json($this->queries->toSummary($proposal), 201);
    }

    public function update(UpdateProposalStatusRequest $request, string $id): JsonResponse
    {
        $perimeter = $this->perimeter($request);
        $proposal = $this->queries->findInPerimeter($perimeter, $id);

        if ($proposal === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            $updated = $this->updateStatus->execute(
                $perimeter,
                $this->apiKey($request),
                $proposal,
                new UpdateProposalStatusData(
                    status: ProposalStatusEnum::from($request->validated('status')),
                ),
                'api',
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->queries->toSummary($updated));
    }
}
