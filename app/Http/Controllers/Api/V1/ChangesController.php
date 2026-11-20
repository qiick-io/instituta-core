<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContractErrorCodeEnum;
use App\Exceptions\ContractException;
use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListChangesRequest;
use App\Http\Resources\Api\ApiMessageResource;
use App\Http\Resources\Api\BrainContractResource;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;

/**
 * REST change feed (#228): what the syncs created, updated and removed since a date or version.
 */
final class ChangesController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(ListChangesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $perimeter = $this->perimeter($request);

        if ($validated['scope'] !== $perimeter->scopeId) {
            return (new ApiMessageResource(['message' => 'Not found.']))->response()->setStatusCode(404);
        }

        try {
            return (new BrainContractResource($this->contract->changes(
                $perimeter,
                $this->apiKey($request),
                $validated['since'],
                $validated['area'] ?? null,
                $validated['cursor'] ?? null,
                isset($validated['limit']) ? (int) $validated['limit'] : 50,
            )))->response();
        } catch (ContractException $exception) {
            return (new ApiMessageResource(['message' => $exception->getMessage(), 'error' => $exception->errorCode->value]))
                ->response()
                ->setStatusCode($exception->errorCode === ContractErrorCodeEnum::NotFound ? 404 : 422);
        }
    }
}
