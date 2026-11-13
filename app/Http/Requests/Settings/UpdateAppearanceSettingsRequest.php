<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\ThemePresetEnum;
use App\Services\SettingsRepositoryService;
use App\Traits\AuthorizesInstanceSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates Settings → Appearance: theme preset, optional custom brand colours and the
 * default light/dark/system theme. Logo and favicon uploads are not ported yet.
 */
final class UpdateAppearanceSettingsRequest extends FormRequest
{
    use AuthorizesInstanceSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'theme_preset' => ['required', 'string', Rule::in(ThemePresetEnum::values())],
            'project_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'project_color_dark' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'default_appearance' => ['required', 'string', Rule::in(SettingsRepositoryService::APPEARANCES)],
        ];
    }

    /**
     * @return array{theme_preset: string, project_color: string|null, project_color_dark: string|null, default_appearance: string}
     */
    public function settings(): array
    {
        return [
            'theme_preset' => $this->string('theme_preset')->toString(),
            'project_color' => $this->string('project_color')->toString() ?: null,
            'project_color_dark' => $this->string('project_color_dark')->toString() ?: null,
            'default_appearance' => $this->string('default_appearance')->toString(),
        ];
    }

    /**
     * A request without `theme_preset` keeps the stored preset, as in externa-core.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('theme_preset')) {
            $this->merge(['theme_preset' => app(SettingsRepositoryService::class)->themePreset()]);
        }
    }
}
