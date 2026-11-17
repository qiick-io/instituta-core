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

    /**
     * The brain area of a file from its top folder (`brand/` reads as `style`); anything else is a note.
     * Shared by the markdown adapter and the asset reader, so a logo sits in the area of its folder.
     */
    public static function areaFromPath(string $relativePath): string
    {
        $parts = explode('/', str_replace('\\', '/', $relativePath));
        $first = strtolower($parts[0]);

        return match ($first) {
            'rules', 'decisions', 'style', 'brand', 'glossary', 'plans', 'notes' => $first === 'brand' ? 'style' : $first,
            'design-system', 'design_system', 'designsystem' => 'design-system',
            'connected-systems', 'connected_systems' => 'connected-systems',
            default => 'notes',
        };
    }
}
