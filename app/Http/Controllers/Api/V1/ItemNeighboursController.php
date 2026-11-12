<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItemNeighboursController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'depth' => ['nullable', 'integer', 'min:1', 'max:2'],
        ]);

        return response()->json(
            $this->contract->graph(
                $this->perimeter($request),
                $this->apiKey($request),
                $id,
                isset($validated['depth']) ? (int) $validated['depth'] : 1,
            ),
        );
    }
}
