<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\BrainWriteGateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses REST write routes unless the kill switch and brain:write allow it.
 */
final class RequireBrainWriteMiddleware
{
    public function __construct(
        private readonly BrainWriteGateService $writeGate = new BrainWriteGateService,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->attributes->get('instituta.api_key');
        if (! $key instanceof ApiKey) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $reason = $this->writeGate->refusalReason($key, 'api');
        if ($reason !== null) {
            return response()->json(['message' => $reason], 403);
        }

        return $next($request);
    }
}
