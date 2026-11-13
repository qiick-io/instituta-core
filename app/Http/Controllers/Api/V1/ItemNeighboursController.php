<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ItemNeighboursRequest;
use App\Http\Resources\Api\BrainContractResource;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;

final class ItemNeighboursController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(ItemNeighboursRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

        return (new BrainContractResource(
            $this->contract->graph(
                $this->perimeter($request),
                $this->apiKey($request),
                $id,
                isset($validated['depth']) ? (int) $validated['depth'] : 1,
            ),
        ))->response();
    }
}
