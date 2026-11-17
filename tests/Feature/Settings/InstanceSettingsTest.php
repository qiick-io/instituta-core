<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Enums\ThemePresetEnum;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use App\Services\ThemePresetService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

/**
 * Admin has can-manage-settings from RoleSeeder.
 */
function settingsManager(): User
{
    return User::factory()->create()->assignRole(RoleEnum::Admin->value);
}

/**
 * @return array<string, mixed>
 */
function generalSettingsPayload(array $overrides = []): array
{
    return [
        'app_name' => 'Acme Brain',
        'description' => 'Company brain for Acme.',
        'report_bug_url' => 'https://example.com/bugs/new',
        'activity_retention_days' => 365,
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function securitySettingsPayload(array $overrides = []): array
{
    return [
        'password_policy' => 'medium',
        'login_max_attempts' => 3,
        'login_lockout_threshold' => 5,
        'login_lockout_minutes' => 15,
        'registration_enabled' => '0',
        'two_factor_required' => '0',
        ...$overrides,
    ];
}

dataset('instance settings pages', [
    'general' => ['instance.edit', 'settings/instance'],
    'localization' => ['instance.localization.edit', 'settings/instance/localization'],
    'authentication & security' => ['instance.security.edit', 'settings/instance/security'],
]);

dataset('instance settings writes', [
    'general' => ['put', 'instance.update'],
    'localization' => ['put', 'instance.localization.update'],
    'authentication & security' => ['put', 'instance.security.update'],
    'appearance' => ['put', 'appearance.update'],
]);

it('shows every instance settings page to managers', function (string $route, string $component): void {
    $this->actingAs(settingsManager())->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with('instance settings pages');

it('shows appearance with theme presets to managers', function (): void {
    $this->actingAs(settingsManager())->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/appearance')
            ->where('appearance.theme_preset', 'salvia')
            ->has('appearance.theme_presets', count(ThemePresetEnum::cases())));
});

it('shows personal appearance without instance form to readers', function (): void {
    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);

    $this->actingAs($reader)->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/appearance')
            ->missing('appearance'));
});

it('keeps every instance settings page from users without can-manage-settings', function (string $route): void {
    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);

    $this->actingAs($reader)->get(route($route))->assertForbidden();
})->with('instance settings pages');

it('keeps every instance settings write from users without can-manage-settings', function (string $method, string $route): void {
    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);

    $this->actingAs($reader)
        ->{$method}(route($route), [...generalSettingsPayload(), ...securitySettingsPayload(), 'default_language' => 'de', 'default_appearance' => 'dark'])
        ->assertForbidden();

    expect(Setting::query()->count())->toBe(0);
})->with('instance settings writes');

it('sends guests to the login page', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with('instance settings pages');

it('shows the defaults until a value is saved', function (): void {
    $manager = settingsManager();

    $this->actingAs($manager)->get(route('instance.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('instance.app_name', null)
            ->where('instance.description', null)
            ->where('instance.report_bug_url', null));

    $this->actingAs($manager)->get(route('instance.security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('instance.login_max_attempts', 5)
            ->where('instance.registration_enabled', false)
            ->where('instance.two_factor_required', false)
            ->where('passwordPolicies', SettingsRepositoryService::PASSWORD_POLICIES));
});

it('saves the general settings and the app uses them', function (): void {
    $this->actingAs(settingsManager())
        ->put(route('instance.update'), generalSettingsPayload())
        ->assertRedirect(route('instance.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    $settings = app(SettingsRepositoryService::class);

    expect($settings->appName())->toBe('Acme Brain')
        ->and($settings->reportBugUrl())->toBe('https://example.com/bugs/new')
        ->and($settings->all()['description'])->toBe('Company brain for Acme.');

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('name', 'Acme Brain')
        ->where('instanceSettings.reportBugUrl', 'https://example.com/bugs/new'));
});

it('validates the general settings', function (): void {
    $this->actingAs(settingsManager())
        ->put(route('instance.update'), generalSettingsPayload([
            'app_name' => str_repeat('a', 101),
            'description' => str_repeat('a', 1001),
            'report_bug_url' => 'not a url',
        ]))
        ->assertSessionHasErrors(['app_name', 'description', 'report_bug_url']);
});

it('saves the authentication and security settings and the app uses them', function (): void {
    $this->actingAs(settingsManager())
        ->put(route('instance.security.update'), securitySettingsPayload())
        ->assertRedirect(route('instance.security.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    $settings = app(SettingsRepositoryService::class);

    expect($settings->passwordPolicy())->toBe('medium')
        ->and($settings->loginMaxAttempts())->toBe(3)
        ->and($settings->isRegistrationEnabled())->toBeFalse()
        ->and($settings->isTwoFactorRequired())->toBeFalse();

    expect(validator(['password' => 'alllowercase'], ['password' => Password::default()])->fails())->toBeTrue()
        ->and(validator(['password' => 'Mixed1Case'], ['password' => Password::default()])->passes())->toBeTrue();
});

it('turns self-registration on from authentication and security settings', function (): void {
    $this->actingAs(settingsManager())
        ->put(route('instance.security.update'), securitySettingsPayload([
            'registration_enabled' => '1',
        ]))
        ->assertRedirect(route('instance.security.edit'));

    expect(app(SettingsRepositoryService::class)->isRegistrationEnabled())->toBeTrue();

    $this->post(route('logout'));

    $this->get(route('register'))->assertOk();
});

it('keeps registration closed until enabled', function (): void {
    $this->get(route('register'))->assertForbidden();
});

it('validates the authentication and security settings', function (): void {
    $this->actingAs(settingsManager())
        ->put(route('instance.security.update'), securitySettingsPayload([
            'password_policy' => 'none',
            'login_max_attempts' => 0,
            'login_lockout_threshold' => 0,
            'login_lockout_minutes' => 0,
            'registration_enabled' => 'maybe',
            'two_factor_required' => 'maybe',
        ]))
        ->assertSessionHasErrors([
            'password_policy',
            'login_max_attempts',
            'login_lockout_threshold',
            'login_lockout_minutes',
            'registration_enabled',
            'two_factor_required',
        ]);
});

it('uses the saved login attempts for the login rate limit', function (): void {
    app(SettingsRepositoryService::class)->save(['login_max_attempts' => 2]);
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 2);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertTooManyRequests();
});

it('sends users without 2FA to their security page when 2FA is required', function (): void {
    $this->actingAs(settingsManager())
        ->put(route('instance.security.update'), securitySettingsPayload(['two_factor_required' => '1']))
        ->assertRedirect(route('instance.security.edit'));

    expect(app(SettingsRepositoryService::class)->isTwoFactorRequired())->toBeTrue();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));

    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->get(route('dashboard'))
        ->assertOk();
});

it('saves the default language and uses it after the user and cookie choice', function (): void {
    $this->actingAs(settingsManager())
        ->put(route('instance.localization.update'), ['default_language' => 'de'])
        ->assertRedirect(route('instance.localization.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    auth()->logout();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('locale', 'de'));
    $this->withUnencryptedCookie('locale', 'it')->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'it'));

    $this->actingAs(User::factory()->create(['locale' => 'en']))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('validates the default language', function (): void {
    $this->actingAs(settingsManager())
        ->put(route('instance.localization.update'), ['default_language' => 'fr'])
        ->assertSessionHasErrors('default_language');
});

it('saves the default appearance and uses it when there is no appearance cookie', function (): void {
    $this->actingAs(settingsManager())
        ->put(route('appearance.update'), ['default_appearance' => 'dark'])
        ->assertRedirect(route('appearance.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    expect(app(SettingsRepositoryService::class)->defaultAppearance())->toBe('dark');

    auth()->logout();

    $this->get(route('login'))->assertSee("const appearance = 'dark';", false);
    $this->withUnencryptedCookie('appearance', 'light')->get(route('login'))
        ->assertSee("const appearance = 'light';", false);
});

it('saves the theme preset and brand colours', function (): void {
    $manager = settingsManager();

    $this->actingAs($manager)
        ->put(route('appearance.update'), [
            'theme_preset' => 'indigo',
            'project_color' => '#123456',
            'project_color_dark' => '',
            'default_appearance' => 'system',
        ])
        ->assertRedirect(route('appearance.edit'))
        ->assertSessionHasNoErrors();

    $settings = app(SettingsRepositoryService::class);

    expect($settings->themePreset())->toBe('indigo')
        ->and($settings->projectColor())->toBe('#123456')
        ->and($settings->projectColorDark())->toBeNull();

    $this->actingAs($manager)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('projectAppearance.themePreset', 'indigo')
            ->where('projectAppearance.themeCss.brandPrimary', '#123456')
            ->where('projectAppearance.themeCss.brandPrimaryDark', '#123456'));
});

it('rejects an unknown theme preset and invalid brand colours', function (): void {
    $this->actingAs(settingsManager())
        ->put(route('appearance.update'), [
            'theme_preset' => 'neon',
            'project_color' => 'red',
            'project_color_dark' => '#12345',
            'default_appearance' => 'system',
        ])
        ->assertSessionHasErrors(['theme_preset', 'project_color', 'project_color_dark']);

    expect(app(SettingsRepositoryService::class)->themePreset())->toBe('salvia');
});

it('merges the shared base with the preset brand colours', function (): void {
    $default = app(ThemePresetService::class)->resolve(null, null, null);
    $violet = app(ThemePresetService::class)->resolve('violet', null, null);

    expect($default['themePreset'])->toBe('salvia')
        ->and($default['light']['primary'])->toBe('#5C7653')
        ->and($default['light']['sidebar'])->toBe(config('theme-presets.base.light.sidebar'))
        ->and($violet['light']['ai'])->toBe(config('theme-presets.ai_override_light.ai'));
});

it('renders the theme variables of a non-default preset in the HTML', function (): void {
    app(SettingsRepositoryService::class)->save(['theme_preset' => 'indigo']);
    $indigo = config('theme-presets.presets.indigo');

    $this->get(route('login'))
        ->assertSee('<style id="instance-theme-css">', false)
        ->assertSee('--primary: '.$indigo['light']['primary'].';', false)
        ->assertSee('--primary: '.$indigo['dark']['primary'].';', false)
        ->assertDontSee('--brand-primary:', false);
});

it('keeps a contrast-safe focus ring for a low-contrast brand colour', function (): void {
    $themeCss = app(ThemePresetService::class)->resolve('teal', '#0F172A', null);

    expect($themeCss['light']['ring'])->toBe('#0F172A')
        ->and($themeCss['dark']['ring'])->toBe('var(--color-teal-400)');
});
