<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The assistant is off until an admin turns it on (`AI_ASSISTANT_ENABLED`, #203): nothing calls a
 * provider before that. The status endpoint stays open so the UI can say how to configure it.
 */
final class EnsureAiAssistantEnabledMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('ai.assistant.enabled')) {
            return response()->json([
                'message' => __('The assistant is off. An admin can turn it on with AI_ASSISTANT_ENABLED and a provider.'),
            ], 503);
        }

        return $next($request);
    }
}
