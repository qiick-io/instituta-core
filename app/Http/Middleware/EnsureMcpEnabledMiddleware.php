<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the HTTP MCP route behind instituta.mcp.enabled (default off).
 */
final class EnsureMcpEnabledMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('instituta.mcp.enabled')) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32000,
                    'message' => 'MCP is disabled. Set INSTITUTA_MCP_ENABLED=true to enable.',
                ],
            ], 404);
        }

        return $next($request);
    }
}
