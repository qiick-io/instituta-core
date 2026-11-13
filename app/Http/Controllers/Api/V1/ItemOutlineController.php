<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiMessageResource;
use App\Http\Resources\Api\BrainContractResource;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST progressive outline for one indexed item (anchors without full bodies).
 */
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
            return (new ApiMessageResource(['message' => 'Not found.']))
                ->response()
                ->setStatusCode(404);
        }

        return (new BrainContractResource($outline))->response();
    }
}
