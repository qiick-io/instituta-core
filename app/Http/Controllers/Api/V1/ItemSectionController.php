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

final class ItemSectionController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(Request $request, string $id, string $anchor): JsonResponse
    {
        $section = $this->contract->getSection(
            $this->perimeter($request),
            $this->apiKey($request),
            $id,
            $anchor,
        );

        if ($section === null) {
            return (new ApiMessageResource(['message' => 'Not found.']))
                ->response()
                ->setStatusCode(404);
        }

        return (new BrainContractResource($section))->response();
    }
}
