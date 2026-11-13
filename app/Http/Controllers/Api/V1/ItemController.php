<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListItemsRequest;
use App\Http\Resources\Api\ApiMessageResource;
use App\Http\Resources\Api\BrainContractResource;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItemController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function index(ListItemsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $perimeter = $this->perimeter($request);
        if ($validated['scope'] !== $perimeter->scopeId) {
            return (new ApiMessageResource(['message' => 'Not found.']))
                ->response()
                ->setStatusCode(404);
        }

        return (new BrainContractResource(
            $this->contract->list(
                $perimeter,
                $this->apiKey($request),
                $validated['area'] ?? null,
                $validated['type'] ?? null,
                $validated['cursor'] ?? null,
                isset($validated['limit']) ? (int) $validated['limit'] : 25,
            ),
        ))->response();
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $item = $this->contract->get(
            $this->perimeter($request),
            $this->apiKey($request),
            $id,
        );

        if ($item === null) {
            return (new ApiMessageResource(['message' => 'Not found.']))
                ->response()
                ->setStatusCode(404);
        }

        return (new BrainContractResource($item))->response();
    }
}
