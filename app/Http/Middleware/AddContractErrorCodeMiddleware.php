<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ContractErrorCodeEnum;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every REST error carries a stable `code` next to its `message` (#227). Responses that set a
 * specific code keep it; the others (validation, throttling, missing routes, framework errors)
 * get the default code of their HTTP status, so an agent can always branch on `code`.
 */
final class AddContractErrorCodeMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload) || array_key_exists('code', $payload) || array_key_exists('jsonrpc', $payload)) {
            return $response;
        }

        $payload['code'] = ContractErrorCodeEnum::forStatus($response->getStatusCode())->value;

        return $response->setData($payload);
    }
}
