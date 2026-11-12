<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItemOutlineController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $outline = $this->contract->outline(
            $this->perimeter($request),
            $this->apiKey($request),
            $id,
        );

        if ($outline === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json($outline);
    }
}
