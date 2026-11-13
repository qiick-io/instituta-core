<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsInstanceSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateAppearanceSettingsRequest;
use App\Models\Setting;
use App\Services\SettingsRepositoryService;
use App\Services\ThemePresetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Appearance: personal light/dark/system for everyone; theme preset and
 * instance default for managers (SettingPolicy). Branding file uploads deferred.
 */
final class AppearanceSettingsController extends Controller
{
    use LogsInstanceSettingsUpdateTrait;

    public function __construct(
        private readonly SettingsRepositoryService $settings,
        private readonly ThemePresetService $themePresets,
    ) {}

    public function edit(Request $request): Response
    {
        $payload = [];

        if ($request->user()?->can('update', Setting::class)) {
            $payload['appearance'] = [
                'theme_preset' => $this->settings->themePreset(),
                'project_color' => $this->settings->projectColor(),
                'project_color_dark' => $this->settings->projectColorDark(),
                'default_appearance' => $this->settings->defaultAppearance(),
                'theme_presets' => $this->themePresets->catalog(),
                'brand_passes_aa' => $this->themePresets->current()['brandPassesAa'],
            ];
        }

        return Inertia::render('settings/appearance', $payload);
    }

    public function update(UpdateAppearanceSettingsRequest $request): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
        $this->logInstanceSettingsUpdate($request, 'appearance', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('appearance.edit');
    }
}
