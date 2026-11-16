<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SearchRequest;
use App\Http\Resources\Api\ApiMessageResource;
use App\Http\Resources\Api\BrainContractResource;
use App\Services\HybridSearchService;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * REST full-text search over indexed items inside the API key perimeter.
 */
final class SearchController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(SearchRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $perimeter = $this->perimeter($request);
        if ($validated['scope'] !== $perimeter->scopeId) {
            return (new ApiMessageResource(['message' => 'Not found.']))
                ->response()
                ->setStatusCode(404);
        }

        try {
            return (new BrainContractResource(
                $this->contract->search(
                    $perimeter,
                    $this->apiKey($request),
                    $validated['q'],
                    $validated['area'] ?? null,
                    isset($validated['limit']) ? (int) $validated['limit'] : 20,
                    $validated['mode'] ?? HybridSearchService::MODE_HYBRID,
                ),
            ))->response();
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'search driver not supported') {
                return (new ApiMessageResource([
                    'message' => 'search driver not supported',
                ]))->response()->setStatusCode(501);
            }

            throw $exception;
        }
    }
}
