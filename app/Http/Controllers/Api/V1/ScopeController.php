<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Services\IndexedContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ScopeController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly IndexedContractService $contract) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(
            $this->contract->scopes($this->perimeter($request), $this->apiKey($request)),
        );
    }
}
