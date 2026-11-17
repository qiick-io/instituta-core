<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Built-in appearance theme presets (config/theme-presets.php): Instituta's own Salvia (#269),
 * the default, plus the generic presets, named after Italian pigments and stones (#310).
 * A sibling product's brand colour (Externa's teal, Excubia's blue) is never offered here.
 */
enum ThemePresetEnum: string
{
    case Salvia = 'salvia';
    case Indaco = 'indaco';
    case Ametista = 'ametista';
    case Robbia = 'robbia';
    case Ardesia = 'ardesia';

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
