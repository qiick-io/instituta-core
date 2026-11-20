<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContractErrorCodeEnum;
use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ContextRequest;
use App\Http\Resources\Api\ApiMessageResource;
use App\Http\Resources\Api\BrainContractResource;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * REST context pack (#224): resolved entries and the most relevant sections, read, within a
 * token budget, in one call.
 */
final class ContextController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(ContextRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $perimeter = $this->perimeter($request);

        if ($validated['scope'] !== $perimeter->scopeId) {
            return (new ApiMessageResource(['message' => 'Not found.']))->response()->setStatusCode(404);
        }

        try {
            return (new BrainContractResource($this->contract->context(
                $perimeter,
                $this->apiKey($request),
                $validated['q'],
                $validated['product_id'] ?? null,
                $validated['area'] ?? null,
                isset($validated['budget']) ? (int) $validated['budget'] : IndexedContractService::CONTEXT_DEFAULT_BUDGET,
            )))->response();
        } catch (RuntimeException $exception) {
            return (new ApiMessageResource([
                'message' => $exception->getMessage(),
                'error' => ContractErrorCodeEnum::SearchUnsupported->value,
            ]))->response()->setStatusCode(501);
        }
    }
}
