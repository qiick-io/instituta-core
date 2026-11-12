<?php

declare(strict_types=1);

namespace App\Brain\Scanners;

use App\Brain\Exceptions\DenylistHitException;

/**
 * Never-ingest guard: case-insensitive match on path, remote URL, or name.
 */
final class DenylistScanner
{
    /**
     * @param  list<string>  $denylist
     * @param  list<string>  $haystacks  Absolute path, remote URL, owner/name, etc.
     *
     * @throws DenylistHitException
     */
    public function assertAllowed(array $denylist, array $haystacks): void
    {
        $match = $this->firstMatch($denylist, $haystacks);
        if ($match === null) {
            return;
        }

        throw new DenylistHitException($match['token'], $match['haystack']);
    }

    /**
     * @param  list<string>  $denylist
     * @param  list<string>  $haystacks
     * @return array{token: string, haystack: string}|null
     */
    public function firstMatch(array $denylist, array $haystacks): ?array
    {
        foreach ($denylist as $token) {
            $needle = strtolower(trim($token));
            if ($needle === '') {
                continue;
            }

            foreach ($haystacks as $haystack) {
                if ($haystack === '') {
                    continue;
                }

                if (str_contains(strtolower($haystack), $needle)) {
                    return [
                        'token' => $token,
                        'haystack' => $haystack,
                    ];
                }
            }
        }

        return null;
    }
}
