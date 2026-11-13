<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Principal;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads and authenticates API keys. Writes live in CreateApiKeyAction / RevokeApiKeyAction.
 */
final class ApiKeyIssuerService
{
    /**
     * @return Collection<int, ApiKey>
     */
    public function listForPrincipal(Principal $principal): Collection
    {
        return $principal->apiKeys()->orderByDesc('created_at')->get();
    }

    public function findByPlainSecret(string $plain): ?ApiKey
    {
        /** @var ApiKey|null $key */
        $key = ApiKey::query()
            ->where('hash', hash('sha256', $plain))
            ->first();

        return $key;
    }

    /**
     * @return array{plain: string, display_prefix: string, hash: string}
     */
    public function makeSecret(string $prefix = ApiKey::DEFAULT_PREFIX): array
    {
        $plain = $prefix.Str::random(40);
        $displayPrefix = substr($plain, 0, 12);

        return [
            'plain' => $plain,
            'display_prefix' => $displayPrefix,
            'hash' => hash('sha256', $plain),
        ];
    }
}
