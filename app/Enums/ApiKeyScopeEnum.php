<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * API key permission vocabulary for Instituta (qiick-io/app-ui#171).
 *
 * Wire values follow `^[a-z][a-z0-9-]*:(read|write)$`. A key stores one scope
 * per resource (the highest); `write` implies `read` on the same resource.
 */
enum ApiKeyScopeEnum: string
{
    case BrainRead = 'brain:read';
    case BrainWrite = 'brain:write';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether a stored scopes list satisfies a required scope (write implies read).
     *
     * An empty list grants nothing — fail closed.
     *
     * @param  list<string>  $scopes
     */
    public static function grants(array $scopes, self|string $required): bool
    {
        if ($scopes === []) {
            return false;
        }

        $needed = $required instanceof self ? $required->value : $required;

        if (in_array($needed, $scopes, true)) {
            return true;
        }

        if (! str_ends_with($needed, ':read')) {
            return false;
        }

        $resource = substr($needed, 0, -strlen(':read'));

        return in_array($resource.':write', $scopes, true);
    }

    /**
     * Collapse to one scope per resource (write wins). Values outside the enum are dropped.
     *
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public static function normalize(array $scopes): array
    {
        $allowed = self::values();
        $byResource = [];

        foreach ($scopes as $scope) {
            if (! in_array($scope, $allowed, true)) {
                continue;
            }

            [$resource, $action] = explode(':', $scope, 2);

            if ($action === 'write' || ! isset($byResource[$resource])) {
                $byResource[$resource] = $scope;
            }
        }

        return array_values($byResource);
    }
}
