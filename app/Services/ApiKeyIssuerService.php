<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Principal;
use Illuminate\Support\Collection;

/**
 * Reads and authenticates API keys. Writes live in Create / Rotate / Revoke actions.
 *
 * Token format (fleet): `ins_{live|test}_{id}{secret}` where `id` is an 8-character
 * public identifier stored in `key_prefix` as `ins_{env}_{id}`, and `secret` is at
 * least 32 base62 characters. The stored hash is SHA-256 of the full token.
 */
final class ApiKeyIssuerService
{
    /** Base62 alphabet (URL-safe, no ambiguous punctuation). */
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /** Full-token shape for scanners and auth parsing. */
    public const string TOKEN_PATTERN = '/^(ins)_(live|test)_([A-Za-z0-9]{8})([A-Za-z0-9]{32,})$/D';

    /**
     * @return Collection<int, ApiKey>
     */
    public function listForPrincipal(Principal $principal): Collection
    {
        return $principal->apiKeys()->orderByDesc('created_at')->get();
    }

    /**
     * Look up by the public key_prefix, then verify the full token with hash_equals.
     */
    public function findByPlainSecret(string $plain): ?ApiKey
    {
        $publicPart = $this->publicPartFromToken($plain);

        if ($publicPart === null) {
            return null;
        }

        /** @var ApiKey|null $key */
        $key = ApiKey::query()
            ->where('key_prefix', $publicPart)
            ->first();

        if ($key === null || ! $key->matchesToken($plain)) {
            return null;
        }

        return $key;
    }

    /**
     * @return array{plain: string, key_prefix: string, hash: string}
     */
    public function makeSecret(?string $productPrefix = null): array
    {
        $product = $productPrefix ?? (string) config('api.key_prefix', ApiKey::DEFAULT_PREFIX);
        $environment = app()->isProduction() ? 'live' : 'test';
        $publicId = $this->randomAlphabet(8);
        $secret = $this->randomAlphabet(32);
        $keyPrefix = $product.'_'.$environment.'_'.$publicId;
        $plain = $keyPrefix.$secret;

        return [
            'plain' => $plain,
            'key_prefix' => $keyPrefix,
            'hash' => hash('sha256', $plain),
        ];
    }

    /**
     * New secret for an existing key: same public key_prefix, new full-token hash.
     *
     * @return array{plain: string, key_prefix: string, hash: string}
     */
    public function makeRotatedSecret(ApiKey $key): array
    {
        $secret = $this->randomAlphabet(32);
        $plain = $key->key_prefix.$secret;

        return [
            'plain' => $plain,
            'key_prefix' => $key->key_prefix,
            'hash' => hash('sha256', $plain),
        ];
    }

    public function publicPartFromToken(string $plain): ?string
    {
        if (preg_match(self::TOKEN_PATTERN, $plain, $matches) !== 1) {
            return null;
        }

        return $matches[1].'_'.$matches[2].'_'.$matches[3];
    }

    private function randomAlphabet(int $length): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $output = '';

        for ($index = 0; $index < $length; $index++) {
            $output .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $output;
    }
}
