<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimilarSearchRequest;
use App\Http\Resources\Api\ApiMessageResource;
use App\Http\Resources\Api\BrainContractResource;
use App\Services\BrainEmbeddingService;
use App\Services\ContractAuditService;
use Illuminate\Http\JsonResponse;

/**
 * Embedding neighbour search inside the API key perimeter (#65).
 */
final class SimilarSearchController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(
        private readonly BrainEmbeddingService $embeddings,
        private readonly ContractAuditService $audit = new ContractAuditService,
    ) {}

    public function __invoke(SimilarSearchRequest $request): JsonResponse
    {
        if (! config('ai.embeddings.enabled')) {
            return (new ApiMessageResource([
                'message' => 'embeddings disabled',
            ]))->response()->setStatusCode(501);
        }

        $validated = $request->validated();

        $perimeter = $this->perimeter($request);

        if ($validated['scope'] !== $perimeter->scopeId) {
            return (new ApiMessageResource(['message' => 'Not found.']))
                ->response()
                ->setStatusCode(404);
        }

        $results = $this->embeddings->similar(
            $perimeter,
            $validated['q'],
            isset($validated['limit']) ? (int) $validated['limit'] : 10,
        );

        $this->audit->record(
            'instituta_similar',
            $perimeter,
            $this->apiKey($request),
            array_map(
                static fn (array $row): string => $row['section_id'],
                $results,
            ),
        );

        return (new BrainContractResource([
            'query' => $validated['q'],
            'results' => $results,
        ]))->response();
    }
}
