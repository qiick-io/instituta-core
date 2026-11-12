<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Brain\Data\PerimeterData;
use App\Models\ApiKey;
use Illuminate\Http\Request;
use RuntimeException;

trait ResolvesInstitutaApiContextTrait
{
    protected function perimeter(Request $request): PerimeterData
    {
        $perimeter = $request->attributes->get('instituta.perimeter');
        if (! $perimeter instanceof PerimeterData) {
            throw new RuntimeException('API perimeter is not set.');
        }

        return $perimeter;
    }

    protected function apiKey(Request $request): ApiKey
    {
        $key = $request->attributes->get('instituta.api_key');
        if (! $key instanceof ApiKey) {
            throw new RuntimeException('API key is not set.');
        }

        return $key;
    }
}
