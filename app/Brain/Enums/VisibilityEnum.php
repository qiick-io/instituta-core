<?php

declare(strict_types=1);

namespace App\Brain\Enums;

enum VisibilityEnum: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Restricted = 'restricted';

    public static function tryFromLoose(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
