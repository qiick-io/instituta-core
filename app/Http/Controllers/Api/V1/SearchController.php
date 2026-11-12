<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SearchController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string'],
            'q' => ['required', 'string'],
            'area' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $perimeter = $this->perimeter($request);
        if ($validated['scope'] !== $perimeter->scopeId) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            return response()->json(
                $this->contract->search(
                    $perimeter,
                    $this->apiKey($request),
                    $validated['q'],
                    $validated['area'] ?? null,
                    isset($validated['limit']) ? (int) $validated['limit'] : 20,
                ),
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'search driver not supported') {
                return response()->json([
                    'message' => 'search driver not supported',
                ], 501);
            }

            throw $exception;
        }
    }
}
