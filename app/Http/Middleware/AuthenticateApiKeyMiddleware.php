<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiKeyAbilityEnum;
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
            return $this->unauthorized('Missing Bearer API key.');
        }

        $key = $this->keys->findByPlainSecret($plain);
        if ($key === null) {
            return $this->unauthorized('Invalid API key.');
        }

        if (! in_array(ApiKeyAbilityEnum::BrainRead->value, $key->abilities, true)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32003,
                    'message' => 'Ability brain:read is required.',
                ],
            ], 403);
        }

        try {
            $perimeter = $this->perimeters->fromApiKey($key->loadMissing('principal'));
        } catch (InvalidArgumentException $exception) {
            return $this->unauthorized($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->unauthorized('API key cannot be resolved.');
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

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32001,
                'message' => $message,
            ],
        ], 401);
    }
}
