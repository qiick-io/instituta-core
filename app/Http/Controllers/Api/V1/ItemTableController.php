<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ItemTableRequest;
use App\Http\Resources\Api\ApiMessageResource;
use App\Http\Resources\Api\BrainContractResource;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;

final class ItemTableController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(ItemTableRequest $request, string $id, string $key): JsonResponse
    {
        $validated = $request->validated();

        $filter = $this->parseFilter($validated['filter'] ?? null);

        $table = $this->contract->table(
            $this->perimeter($request),
            $this->apiKey($request),
            $id,
            $key,
            $filter,
        );

        if ($table === null) {
            return (new ApiMessageResource(['message' => 'Not found.']))
                ->response()
                ->setStatusCode(404);
        }

        return (new BrainContractResource($table))->response();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseFilter(mixed $filter): ?array
    {
        if ($filter === null || $filter === '') {
            return null;
        }

        if (is_array($filter)) {
            /** @var array<string, mixed> $filter */
            return $filter;
        }

        if (! is_string($filter)) {
            return null;
        }

        $decoded = json_decode($filter, true);

        return is_array($decoded) ? $decoded : null;
    }
}
