<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItemController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string'],
            'area' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'cursor' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perimeter = $this->perimeter($request);
        if ($validated['scope'] !== $perimeter->scopeId) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(
            $this->contract->list(
                $perimeter,
                $this->apiKey($request),
                $validated['area'] ?? null,
                $validated['type'] ?? null,
                $validated['cursor'] ?? null,
                isset($validated['limit']) ? (int) $validated['limit'] : 25,
            ),
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $item = $this->contract->get(
            $this->perimeter($request),
            $this->apiKey($request),
            $id,
        );

        if ($item === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json($item);
    }
}
