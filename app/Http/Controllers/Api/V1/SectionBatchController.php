<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GetSectionsRequest;
use App\Http\Resources\Api\BrainContractResource;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;

/**
 * REST batch read of sections (#225): `POST /sections` answers each pair like
 * `GET /items/{id}/sections/{anchor}`, with `error: not_found` per missing entry.
 */
final class SectionBatchController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(GetSectionsRequest $request): JsonResponse
    {
        return (new BrainContractResource($this->contract->getSections(
            $this->perimeter($request),
            $this->apiKey($request),
            $request->requestedSections(),
        )))->response();
    }
}
