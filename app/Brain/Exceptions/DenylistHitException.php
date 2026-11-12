<?php

declare(strict_types=1);

namespace App\Brain\Exceptions;

use RuntimeException;

final class DenylistHitException extends RuntimeException
{
    public function __construct(
        public readonly string $matchedToken,
        public readonly string $haystack,
    ) {
        parent::__construct(
            sprintf('Never-ingest denylist hit: "%s" matched in "%s".', $matchedToken, $haystack),
        );
    }
}
