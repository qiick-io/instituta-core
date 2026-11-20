<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ProposeBrainChangeAction;
use App\Actions\UpdateProposalStatusAction;
use App\Data\ProposeBrainChangeData;
use App\Data\UpdateProposalStatusData;
use App\Enums\ProposalStatusEnum;
use App\Exceptions\ContractException;
use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListProposalsRequest;
use App\Http\Requests\Api\ProposeBrainChangeRequest;
use App\Http\Requests\Api\UpdateProposalStatusRequest;
use App\Http\Resources\Api\ApiMessageResource;
use App\Http\Resources\Api\ProposalSummaryResource;
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

        return (new ProposalSummaryResource(
            $this->queries->list(
                $this->perimeter($request),
                $this->apiKey($request),
                $status,
                'api',
            ),
        ))->response();
    }

    public function store(ProposeBrainChangeRequest $request): JsonResponse
    {
        try {
            $proposal = $this->propose->execute(
                $this->perimeter($request),
                $this->apiKey($request),
                ProposeBrainChangeData::fromInput($request->validated()),
                'api',
            );
        } catch (InvalidArgumentException $exception) {
            return (new ApiMessageResource([
                'message' => $exception->getMessage(),
                'error' => ContractException::codeOf($exception)->value,
            ]))
                ->response()
                ->setStatusCode(422);
        }

        return (new ProposalSummaryResource($this->queries->toProposedSummary($proposal)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateProposalStatusRequest $request, string $id): JsonResponse
    {
        $perimeter = $this->perimeter($request);
        $proposal = $this->queries->findInPerimeter($perimeter, $id);

        if ($proposal === null) {
            return (new ApiMessageResource(['message' => 'Not found.']))
                ->response()
                ->setStatusCode(404);
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
            return (new ApiMessageResource([
                'message' => $exception->getMessage(),
                'error' => ContractException::codeOf($exception)->value,
            ]))
                ->response()
                ->setStatusCode(422);
        }

        return (new ProposalSummaryResource($this->queries->toSummary($updated)))->response();
    }
}
