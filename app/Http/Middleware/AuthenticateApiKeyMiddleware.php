<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiKeyAbilityEnum;
use App\Enums\ContractErrorCodeEnum;
use App\Services\ApiKeyIssuerService;
use App\Services\PerimeterResolverService;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Resolves Authorization: Bearer <api_key> into perimeter + api key attributes.
 */
final class AuthenticateApiKeyMiddleware
{
    public function __construct(
        private readonly ApiKeyIssuerService $keys,
        private readonly PerimeterResolverService $perimeters,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $this->bearerToken($request);
        if ($plain === null) {
            return $this->unauthorized($request, 'Missing Bearer API key.');
        }

        $key = $this->keys->findByPlainSecret($plain);
        if ($key === null) {
            return $this->unauthorized($request, 'Invalid API key.');
        }

        if ($key->revoked_at !== null) {
            return $this->unauthorized($request, 'API key has been revoked.');
        }

        if ($key->expires_at !== null && $key->expires_at->isPast()) {
            return $this->unauthorized($request, 'API key has expired.');
        }

        $key->loadMissing('principal');
        $principal = $key->principal;
        if ($principal === null || $principal->deactivated_at !== null) {
            return $this->unauthorized($request, 'API key principal is inactive.');
        }

        if ($principal->expires_at !== null && $principal->expires_at->isPast()) {
            return $this->unauthorized($request, 'API key principal has expired.');
        }

        if (! in_array(ApiKeyAbilityEnum::BrainRead->value, $key->abilities, true)) {
            return $this->forbidden($request, 'Ability brain:read is required.', ContractErrorCodeEnum::AbilityMissing);
        }

        try {
            $perimeter = $this->perimeters->fromApiKey($key);
        } catch (InvalidArgumentException $exception) {
            return $this->unauthorized($request, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->unauthorized($request, 'API key cannot be resolved.');
        }

        $key->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('instituta.api_key', $key);
        $request->attributes->set('instituta.perimeter', $perimeter);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    private function isRestApi(Request $request): bool
    {
        return $request->is('api/*');
    }

    private function unauthorized(Request $request, string $message): Response
    {
        return $this->refuse($request, $message, 401, -32001, ContractErrorCodeEnum::Unauthenticated);
    }

    private function forbidden(Request $request, string $message, ContractErrorCodeEnum $code = ContractErrorCodeEnum::Forbidden): Response
    {
        return $this->refuse($request, $message, 403, -32003, $code);
    }

    /**
     * REST answers `{message, code}`; MCP a JSON-RPC error whose `data.code` carries the same
     * contract code (#227).
     */
    private function refuse(Request $request, string $message, int $status, int $jsonRpcCode, ContractErrorCodeEnum $code): Response
    {
        if ($this->isRestApi($request)) {
            return response()->json(['message' => $message, 'code' => $code->value], $status);
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $jsonRpcCode,
                'message' => $message,
                'data' => ['code' => $code->value],
            ],
        ], $status);
    }
}
