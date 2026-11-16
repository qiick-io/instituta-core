<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProductResolveRequest;
use App\Http\Resources\Api\BrainContractResource;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;

/**
 * REST product resolve: inherited values with origins for one product scope.
 */
final class ProductResolveController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(ProductResolveRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

        return (new BrainContractResource(
            $this->contract->resolve(
                $this->perimeter($request),
                $this->apiKey($request),
                $id,
                $validated['area'] ?? null,
                (bool) ($validated['include_inactive'] ?? false),
            ),
        ))->response();
    }
}
