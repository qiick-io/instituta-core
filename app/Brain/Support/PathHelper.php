<?php

declare(strict_types=1);

namespace App\Brain\Support;

final class PathHelper
{
    public static function posixRelative(string $root, string $absolute): string
    {
        $root = self::normalize($root);
        $absolute = self::normalize($absolute);

        if (str_starts_with($absolute, $root.'/')) {
            return substr($absolute, strlen($root) + 1);
        }

        if ($absolute === $root) {
            return '';
        }

        return ltrim(str_replace('\\', '/', $absolute), '/');
    }

    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return rtrim($path, '/');
    }

    public static function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    public static function filename(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME) ?: basename($path);
    }
}
