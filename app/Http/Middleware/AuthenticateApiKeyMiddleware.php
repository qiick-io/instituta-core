<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiKeyScopeEnum;
use App\Enums\ContractErrorCodeEnum;
use App\Models\ApiKey;
use App\Services\ApiKeyIssuerService;
use App\Services\PerimeterResolverService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Resolves Authorization: Bearer <api_key> into perimeter + api key attributes.
 *
 * Never establishes a web session. Failures use RFC 6750 `error` values alongside
 * the existing contract `code` so agents already branching on `code` keep working.
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
            return $this->invalidToken($request, 'Missing Bearer API key.');
        }

        $key = $this->keys->findByPlainSecret($plain);

        if ($key === null) {
            return $this->invalidToken($request, 'Invalid API key.');
        }

        if ($key->revoked_at !== null) {
            return $this->invalidToken($request, 'API key has been revoked.');
        }

        if ($key->expires_at !== null && $key->expires_at->isPast()) {
            return $this->invalidToken($request, 'API key has expired.');
        }

        $key->loadMissing('principal');
        $principal = $key->principal;

        if ($principal === null || $principal->deactivated_at !== null) {
            return $this->invalidToken($request, 'API key principal is inactive.');
        }

        if ($principal->expires_at !== null && $principal->expires_at->isPast()) {
            return $this->invalidToken($request, 'API key principal has expired.');
        }

        if (! $key->hasScope(ApiKeyScopeEnum::BrainRead)) {
            return $this->insufficientScope($request, 'Scope brain:read is required.', ApiKeyScopeEnum::BrainRead->value);
        }

        $clientIp = (string) $request->ip();

        if (! $key->allowsIp($clientIp)) {
            return $this->forbidden($request, 'API key is not allowed from this IP address.');
        }

        $rateLimit = $key->rate_limit_per_minute
            ?? (int) config('api.default_rate_limit_per_minute', 60);

        if ($rateLimit > 0) {
            $rateKey = 'api-key:'.$key->getKey();

            if (RateLimiter::tooManyAttempts($rateKey, $rateLimit)) {
                return $this->refuse(
                    $request,
                    'API key rate limit exceeded.',
                    429,
                    -32029,
                    ContractErrorCodeEnum::RateLimited,
                    null,
                );
            }

            RateLimiter::hit($rateKey, 60);
        }

        try {
            $perimeter = $this->perimeters->fromApiKey($key);
        } catch (InvalidArgumentException $exception) {
            return $this->invalidToken($request, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->invalidToken($request, 'API key cannot be resolved.');
        }

        $this->touchLastUsed($key, $clientIp);

        // API-key auth binds the key on the request only — never a web session.
        $request->attributes->set('instituta.api_key', $key);
        $request->attributes->set('instituta.perimeter', $perimeter);

        return $next($request);
    }

    private function touchLastUsed(ApiKey $key, string $clientIp): void
    {
        $lastUsedAt = $key->last_used_at;

        if ($lastUsedAt !== null && $lastUsedAt->gt(now()->subMinute())) {
            return;
        }

        $key->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $clientIp,
        ])->save();
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        // Do not trim: whitespace (including a trailing newline) must fail the
        // token pattern itself, not be silently stripped before validation.
        $token = substr($header, 7);

        return $token !== '' ? $token : null;
    }

    private function isRestApi(Request $request): bool
    {
        return $request->is('api/*');
    }

    private function invalidToken(Request $request, string $message): Response
    {
        return $this->refuse(
            $request,
            $message,
            401,
            -32001,
            ContractErrorCodeEnum::Unauthenticated,
            'invalid_token',
        );
    }

    private function insufficientScope(Request $request, string $message, string $scope): Response
    {
        return $this->refuse(
            $request,
            $message,
            403,
            -32003,
            ContractErrorCodeEnum::AbilityMissing,
            'insufficient_scope',
            $scope,
        );
    }

    private function forbidden(Request $request, string $message): Response
    {
        return $this->refuse(
            $request,
            $message,
            403,
            -32003,
            ContractErrorCodeEnum::Forbidden,
            null,
        );
    }

    /**
     * REST answers `{message, code, error?}`; MCP a JSON-RPC error whose `data.code`
     * carries the contract code (#227). RFC 6750 `error` is set on REST when given.
     */
    private function refuse(
        Request $request,
        string $message,
        int $status,
        int $jsonRpcCode,
        ContractErrorCodeEnum $code,
        ?string $rfcError,
        ?string $scope = null,
    ): Response {
        if ($this->isRestApi($request)) {
            // Fleet machine field is `error` (contract enum). RFC 6750 stays in WWW-Authenticate.
            $payload = ['message' => $message, 'error' => $code->value];

            if ($scope !== null) {
                $payload['scope'] = $scope;
            }

            $response = response()->json($payload, $status);

            if ($rfcError !== null) {
                $response->headers->set('WWW-Authenticate', 'Bearer error="'.$rfcError.'"');
            }

            return $response;
        }

        $data = ['error' => $code->value];

        if ($rfcError !== null) {
            $data['oauth_error'] = $rfcError;
        }

        if ($scope !== null) {
            $data['scope'] = $scope;
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $jsonRpcCode,
                'message' => $message,
                'data' => $data,
            ],
        ], $status);
    }
}
