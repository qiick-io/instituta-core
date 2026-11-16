<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Brain\Enums\RoleEnum;
use App\Enums\ContractErrorCodeEnum;
use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiMessageResource;
use App\Http\Resources\Api\BrainContractResource;
use App\Services\BrainHealthService;
use App\Services\ContractAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST brain health (#231), for owner keys: what the syncs left wrong, with suggested fixes.
 */
final class BrainHealthController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(
        private readonly BrainHealthService $health,
        private readonly ContractAuditService $audit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $perimeter = $this->perimeter($request);

        if ($perimeter->role !== RoleEnum::Owner) {
            return (new ApiMessageResource(['message' => 'Brain health is for owner keys.', 'code' => ContractErrorCodeEnum::Forbidden->value]))
                ->response()
                ->setStatusCode(403);
        }

        $this->audit->record('GET /health/brain', $perimeter, $this->apiKey($request), []);

        return (new BrainContractResource($this->health->check($perimeter)))->response();
    }
}
