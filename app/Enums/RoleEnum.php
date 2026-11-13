<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Built-in application roles for app-level RBAC (Spatie).
 *
 * Distinct from brain tenancy roles ({@see \App\Brain\Enums\RoleEnum} on grants/keys).
 * Names match every qiick app so Signaculum SSO can map them later.
 */
enum RoleEnum: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Reader = 'reader';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
