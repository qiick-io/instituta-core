<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Exceptions\DenylistHitException;
use App\Brain\Scanners\DenylistScanner;

/**
 * App-layer never-ingest guard over config('instituta.ingest.denylist').
 */
final class DenylistGuardService
{
    public function __construct(
        private readonly DenylistScanner $scanner = new DenylistScanner,
    ) {}

    /**
     * @return list<string>
     */
    public function tokens(): array
    {
        $configured = config('instituta.ingest.denylist', []);
        if (! is_array($configured)) {
            return [];
        }

        $tokens = [];
        foreach ($configured as $token) {
            if (is_string($token) && trim($token) !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Refuse when name, remote URL or local path hits the denylist (case-insensitive).
     *
     * @throws DenylistHitException
     */
    public function assertAllowed(?string $name = null, ?string $remoteUrl = null, ?string $localPath = null): void
    {
        $haystacks = array_values(array_filter(
            [$name ?? '', $remoteUrl ?? '', $localPath ?? ''],
            static fn (string $value): bool => $value !== '',
        ));

        $this->scanner->assertAllowed($this->tokens(), $haystacks);
    }
}
