<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ReportGapAction;
use App\Exceptions\ContractException;
use App\Http\Controllers\Api\V1\Concerns\ResolvesInstitutaApiContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportGapRequest;
use App\Http\Resources\Api\ApiMessageResource;
use App\Http\Resources\Api\BrainContractResource;
use Illuminate\Http\JsonResponse;

/**
 * REST gap reports (#229): any key that can read may report a gap; nothing in the brain changes.
 */
final class GapReportController extends Controller
{
    use ResolvesInstitutaApiContextTrait;

    public function __construct(private readonly ReportGapAction $report) {}

    public function __invoke(ReportGapRequest $request): JsonResponse
    {
        try {
            $gapReport = $this->report->execute(
                $this->perimeter($request),
                $this->apiKey($request),
                $request->toData(),
                'api',
            );
        } catch (ContractException $exception) {
            return (new ApiMessageResource(['message' => $exception->getMessage(), 'code' => $exception->errorCode->value]))
                ->response()
                ->setStatusCode(422);
        }

        return (new BrainContractResource([
            'id' => $gapReport->id,
            'kind' => $gapReport->kind->value,
            'status' => $gapReport->status->value,
            'summary' => $gapReport->summary,
            'related' => $gapReport->related ?? [],
        ]))->response()->setStatusCode(201);
    }
}
