<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ContractErrorCodeEnum;
use App\Enums\QuotaMetricEnum;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a tenant quota would be exceeded; maps to HTTP 429.
 */
final class QuotaExceededException extends Exception
{
    public function __construct(
        public readonly QuotaMetricEnum $metric,
        public readonly int $limitValue,
        public readonly int $used,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : "Quota exceeded for {$metric->value} (used {$used} of {$limitValue}).",
        );
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return false;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'error' => ContractErrorCodeEnum::QuotaExceeded->value,
            'metric' => $this->metric->value,
            'limit' => $this->limitValue,
            'used' => $this->used,
        ], 429);
    }
}
