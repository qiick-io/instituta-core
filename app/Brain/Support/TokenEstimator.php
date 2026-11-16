<?php

declare(strict_types=1);

namespace App\Brain\Support;

/**
 * A rough token count for planning reads (#230): characters divided by four, rounded up. It is
 * the usual rule of thumb for English prose with common tokenizers, not a real count: code,
 * tables and other languages can differ by a third either way.
 */
final class TokenEstimator
{
    public static function forText(?string $text): int
    {
        return $text === null || $text === '' ? 0 : (int) ceil(mb_strlen($text) / 4);
    }
}
