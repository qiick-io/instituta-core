<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\BreakGlassPolicyService;
use App\Services\SettingsRepositoryService;
use App\Traits\AuthorizesInstanceSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates Settings → Authentication & security: password policy, rate limit, lockout,
 * self-registration, required two-factor authentication and single sign-on.
 *
 * SSO-only mode is refused while no break-glass account exists, so turning it on can never lock
 * every administrator out when the identity provider is down, and while self-registration is
 * open, which would create local accounts past it.
 */
final class UpdateInstanceSecuritySettingsRequest extends FormRequest
{
    use AuthorizesInstanceSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password_policy' => ['required', Rule::in(SettingsRepositoryService::PASSWORD_POLICIES)],
            'login_max_attempts' => ['required', 'integer', 'between:1,100'],
            'login_lockout_threshold' => ['required', 'integer', 'between:1,100'],
            'login_lockout_minutes' => ['required', 'integer', 'between:1,1440'],
            'registration_enabled' => ['required', 'boolean'],
            'two_factor_required' => ['required', 'boolean'],
            'oidc_enabled' => ['boolean'],
            'oidc_issuer' => ['nullable', 'required_if_accepted:oidc_enabled', 'url:http,https', 'max:2048'],
            'oidc_client_id' => ['nullable', 'required_if_accepted:oidc_enabled', 'string', 'max:255'],
            'oidc_client_secret' => ['nullable', 'string', 'max:2048'],
            'oidc_button_label' => ['nullable', 'string', 'max:100'],
            'oidc_jit_provisioning' => ['boolean'],
            'oidc_auto_link' => ['boolean'],
            'oidc_only' => ['boolean'],
            'oidc_role_mapping_enabled' => ['boolean'],
            'oidc_organization' => ['nullable', 'required_if_accepted:oidc_role_mapping_enabled', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'oidc_enabled' => __('SSO login'),
            'oidc_issuer' => __('issuer URL'),
            'oidc_client_id' => __('client ID'),
            'oidc_client_secret' => __('client secret'),
            'oidc_button_label' => __('login button label'),
            'oidc_role_mapping_enabled' => __('role sync'),
            'oidc_organization' => __('Signaculum organisation'),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->boolean('oidc_only')) {
                return;
            }

            if ($this->boolean('registration_enabled')) {
                $validator->errors()->add('oidc_only', __('Close self-registration before turning on SSO-only mode.'));
            }

            if (! app(BreakGlassPolicyService::class)->hasBreakGlassAccount()) {
                $validator->errors()->add('oidc_only', __('SSO-only mode needs an active super-admin with two-factor authentication, who can still sign in when the identity provider is down.'));
            }
        }];
    }

    /**
     * Values to store. An empty client secret keeps the stored one (SettingsRepositoryService).
     *
     * @return array{password_policy: string, login_max_attempts: int, login_lockout_threshold: int, login_lockout_minutes: int, registration_enabled: bool, two_factor_required: bool, oidc_enabled: bool, oidc_issuer: string|null, oidc_client_id: string|null, oidc_client_secret: string|null, oidc_button_label: string|null, oidc_jit_provisioning: bool, oidc_auto_link: bool, oidc_only: bool, oidc_role_mapping_enabled: bool, oidc_organization: string|null}
     */
    public function settings(): array
    {
        return [
            'password_policy' => $this->string('password_policy')->toString(),
            'login_max_attempts' => $this->integer('login_max_attempts'),
            'login_lockout_threshold' => $this->integer('login_lockout_threshold'),
            'login_lockout_minutes' => $this->integer('login_lockout_minutes'),
            'registration_enabled' => $this->boolean('registration_enabled'),
            'two_factor_required' => $this->boolean('two_factor_required'),
            'oidc_enabled' => $this->boolean('oidc_enabled'),
            'oidc_issuer' => $this->string('oidc_issuer')->trim()->toString() ?: null,
            'oidc_client_id' => $this->string('oidc_client_id')->trim()->toString() ?: null,
            'oidc_client_secret' => $this->string('oidc_client_secret')->toString() ?: null,
            'oidc_button_label' => $this->string('oidc_button_label')->trim()->toString() ?: null,
            'oidc_jit_provisioning' => $this->boolean('oidc_jit_provisioning'),
            'oidc_auto_link' => $this->boolean('oidc_auto_link'),
            'oidc_only' => $this->boolean('oidc_only'),
            'oidc_role_mapping_enabled' => $this->boolean('oidc_role_mapping_enabled'),
            'oidc_organization' => $this->string('oidc_organization')->trim()->toString() ?: null,
        ];
    }
}
