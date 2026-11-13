<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REST Resource that emits the given array payload without Laravel's `data` wrap.
 *
 * Contract and OpenAPI wire keys stay byte-stable: controllers pass the same
 * arrays services already built for `response()->json(...)`.
 *
 * @property-read array<string, mixed> $resource
 */
abstract class UnwrappedJsonResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;

        return $payload;
    }
}
