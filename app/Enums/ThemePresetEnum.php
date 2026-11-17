<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Built-in appearance theme presets (config/theme-presets.php), as in qiick-io/externa-core,
 * plus Instituta's own Salvia (#269), the default.
 */
enum ThemePresetEnum: string
{
    case Salvia = 'salvia';
    case Teal = 'teal';
    case Blue = 'blue';
    case Indigo = 'indigo';
    case Violet = 'violet';
    case Rose = 'rose';
    case Slate = 'slate';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Instituta's default preset: its product colour, Salvia (knowledge brand kit, #269).
     */
    public static function default(): self
    {
        return self::Salvia;
    }

    public static function tryFromNullable(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::default();
        }

        return self::tryFrom($value) ?? self::default();
    }
}
