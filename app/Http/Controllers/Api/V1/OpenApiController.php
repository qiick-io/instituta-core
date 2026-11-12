<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * Serves the Instituta REST contract as OpenAPI 3.1 JSON (no API key).
 */
final class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $path = resource_path('openapi/instituta-v1.yaml');
        /** @var array<string, mixed> $spec */
        $spec = Yaml::parseFile($path);

        return response()->json($spec, 200, [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
