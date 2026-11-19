<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ThemePresetEnum;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Instance settings stored in the `settings` table, merged over defaults, with typed getters
 * for the code that uses them (login throttle, password rule, self-registration, required 2FA,
 * single sign-on, UI language, appearance and branding colours).
 *
 * Copied from qiick-io/signaculum-core and trimmed (no email templates / branding file uploads).
 * Setting keys keep the externa-core / Excubia names (`project_color`, …) so the apps read the
 * same way; in Instituta "project" means this instance.
 *
 * Secrets (SECRET_KEYS: the OIDC client secret) are stored encrypted, never sent to the edit
 * pages, and kept when a save leaves them empty. The `oidc_*` keys keep Excubia's names.
 *
 * Registered as a singleton; values are read once per instance and reloaded after a write.
 */
final class SettingsRepositoryService
{
    public const array PASSWORD_POLICIES = ['weak', 'medium', 'strong'];

    public const array APPEARANCES = ['system', 'light', 'dark'];

    public const array SECRET_KEYS = ['oidc_client_secret'];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /**
     * Defaults for every key.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'app_name' => null,
            'description' => null,
            'report_bug_url' => null,
            'default_language' => $this->configLanguage(),
            'default_appearance' => 'system',
            'theme_preset' => ThemePresetEnum::default()->value,
            'project_color' => null,
            'project_color_dark' => null,
            'password_policy' => app()->isProduction() ? 'strong' : 'weak',
            'login_max_attempts' => 5,
            'login_lockout_threshold' => 5,
            'login_lockout_minutes' => 15,
            // Closed by default; admins turn this on in Settings → Authentication & security.
            'registration_enabled' => false,
            'two_factor_required' => false,
            'activity_retention_days' => (int) config('activitylog.clean_after_days', 365),
            'oidc_enabled' => false,
            'oidc_issuer' => null,
            'oidc_client_id' => null,
            'oidc_client_secret' => null,
            'oidc_button_label' => null,
            'oidc_jit_provisioning' => false,
            'oidc_auto_link' => false,
            'oidc_only' => false,
            'oidc_role_mapping_enabled' => false,
            'oidc_organization' => null,
        ];
    }

    /**
     * Every setting, stored values over defaults.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values ??= array_merge(
            $this->defaults(),
            Setting::query()->pluck('value', 'key')->all(),
        );
    }

    /**
     * Only the given keys, for one settings page. A secret never leaves the server: ask for
     * `has_<key>` to learn whether one is stored.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function forEditOnly(array $keys): array
    {
        $values = array_diff_key($this->all(), array_flip(self::SECRET_KEYS));

        foreach (self::SECRET_KEYS as $secretKey) {
            $values['has_'.$secretKey] = $this->string($secretKey) !== null;
        }

        return array_intersect_key($values, array_flip($keys));
    }

    /**
     * Saves the given keys in one transaction. A secret is encrypted; an empty one keeps the
     * stored value, so the edit page can leave the field blank.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        foreach (self::SECRET_KEYS as $secretKey) {
            if (! array_key_exists($secretKey, $values)) {
                continue;
            }

            if (is_string($values[$secretKey]) && $values[$secretKey] !== '') {
                $values[$secretKey] = Crypt::encryptString($values[$secretKey]);
            } else {
                unset($values[$secretKey]);
            }
        }

        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        $this->values = null;
    }

    /**
     * The instance name shown in the UI and the browser title; `APP_NAME` until one is saved.
     */
    public function appName(): string
    {
        return $this->string('app_name') ?? (string) config('app.name');
    }

    public function reportBugUrl(): ?string
    {
        return $this->string('report_bug_url');
    }

    /**
     * Default UI locale for people without a personal or cookie choice.
     */
    public function defaultLanguage(): string
    {
        $language = $this->all()['default_language'];

        return is_string($language) && in_array($language, $this->availableLanguages(), true)
            ? $language
            : $this->configLanguage();
    }

    /**
     * Light/dark/system default for visitors without an `appearance` cookie.
     */
    public function defaultAppearance(): string
    {
        $appearance = $this->all()['default_appearance'];

        return is_string($appearance) && in_array($appearance, self::APPEARANCES, true) ? $appearance : 'system';
    }

    public function themePreset(): string
    {
        $preset = $this->all()['theme_preset'];

        return ThemePresetEnum::tryFromNullable(is_string($preset) ? $preset : null)->value;
    }

    /**
     * Custom brand colour (`#RRGGBB`) that overrides the preset primary, or null.
     */
    public function projectColor(): ?string
    {
        return $this->hexColor('project_color');
    }

    /**
     * Custom dark-mode brand colour, or null (the light one is used then).
     */
    public function projectColorDark(): ?string
    {
        return $this->hexColor('project_color_dark');
    }

    public function passwordPolicy(): string
    {
        $policy = $this->all()['password_policy'];

        return in_array($policy, self::PASSWORD_POLICIES, true) ? $policy : 'strong';
    }

    /**
     * The password rule of the saved policy, used as `Password::defaults()`.
     */
    public function passwordRule(): Password
    {
        return match ($this->passwordPolicy()) {
            'weak' => Password::min(6),
            'medium' => Password::min(8)->mixedCase()->numbers(),
            default => Password::min(12)->mixedCase()->letters()->numbers()->symbols()->uncompromised(),
        };
    }

    /**
     * Login attempts per minute for one email and IP (Fortify `login` rate limiter).
     */
    public function loginMaxAttempts(): int
    {
        return $this->integer('login_max_attempts', 1, 100);
    }

    /**
     * Failed password logins before the account is locked (per user, not per IP).
     * Stored for parity with Signaculum; lockout enforcement is a later wiring.
     */
    public function loginLockoutThreshold(): int
    {
        return $this->integer('login_lockout_threshold', 1, 100);
    }

    /**
     * How long a lockout lasts after the failure threshold is reached.
     */
    public function loginLockoutMinutes(): int
    {
        return $this->integer('login_lockout_minutes', 1, 1440);
    }

    /**
     * Whether guests may open `/register` and create an account.
     */
    public function isRegistrationEnabled(): bool
    {
        return (bool) $this->all()['registration_enabled'];
    }

    public function isTwoFactorRequired(): bool
    {
        return (bool) $this->all()['two_factor_required'];
    }

    /**
     * Whether an administrator turned SSO on. The login is available only once the issuer and
     * client id are set too ({@see OidcSettingsRepositoryService::isEnabled()}).
     */
    public function isOidcEnabled(): bool
    {
        return (bool) $this->all()['oidc_enabled'];
    }

    /**
     * The issuer exactly as saved: the package compares it with the IdP's discovery document
     * byte for byte, so no slash is added or removed here.
     */
    public function oidcIssuer(): ?string
    {
        return $this->string('oidc_issuer');
    }

    public function oidcClientId(): ?string
    {
        return $this->string('oidc_client_id');
    }

    /**
     * The decrypted client secret, or null for a public client (PKCE only) or a value that no
     * longer decrypts after an `APP_KEY` change.
     */
    public function oidcClientSecret(): ?string
    {
        $storedSecret = $this->string('oidc_client_secret');

        if ($storedSecret === null) {
            return null;
        }

        try {
            $plainSecret = Crypt::decryptString($storedSecret);
        } catch (DecryptException) {
            return null;
        }

        return $plainSecret !== '' ? $plainSecret : null;
    }

    public function oidcButtonLabel(): ?string
    {
        return $this->string('oidc_button_label');
    }

    /**
     * Create a local account on the first SSO login of an unknown person.
     */
    public function isOidcJitProvisioningEnabled(): bool
    {
        return (bool) $this->all()['oidc_jit_provisioning'];
    }

    /**
     * Link an unknown SSO identity to the local account with the same verified email.
     */
    public function isOidcAutoLinkEnabled(): bool
    {
        return (bool) $this->all()['oidc_auto_link'];
    }

    /**
     * SSO-only mode: local logins are refused except for break-glass accounts.
     */
    public function isOidcOnly(): bool
    {
        return (bool) $this->all()['oidc_only'];
    }

    /**
     * Apply the organisation roles of the `orgs` claim to the local roles at every SSO login.
     */
    public function isOidcRoleMappingEnabled(): bool
    {
        return (bool) $this->all()['oidc_role_mapping_enabled'];
    }

    /**
     * Id or slug of the Signaculum organisation whose roles map to this instance.
     */
    public function oidcOrganization(): ?string
    {
        return $this->string('oidc_organization');
    }

    /**
     * Days to keep activity log rows before `activitylog:clean` deletes them.
     */
    public function activityRetentionDays(): int
    {
        return $this->integer('activity_retention_days', 1, 3650);
    }

    /**
     * @return list<string>
     */
    private function availableLanguages(): array
    {
        return array_keys((array) config('i18n.available_locales', ['en' => 'English']));
    }

    private function configLanguage(): string
    {
        $locale = (string) config('app.locale', 'en');

        return in_array($locale, $this->availableLanguages(), true) ? $locale : 'en';
    }

    private function hexColor(string $key): ?string
    {
        $value = $this->string($key);

        return $value !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : null;
    }

    private function string(string $key): ?string
    {
        $value = $this->all()[$key];

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function integer(string $key, int $min, int $max): int
    {
        $value = $this->all()[$key];

        return max($min, min($max, is_numeric($value) ? (int) $value : (int) $this->defaults()[$key]));
    }
}
