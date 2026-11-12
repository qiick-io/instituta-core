<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItemTableController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(Request $request, string $id, string $key): JsonResponse
    {
        $validated = $request->validate([
            'filter' => ['nullable'],
        ]);

        $filter = $this->parseFilter($validated['filter'] ?? null);

        $table = $this->contract->table(
            $this->perimeter($request),
            $this->apiKey($request),
            $id,
            $key,
            $filter,
        );

        if ($table === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json($table);
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
