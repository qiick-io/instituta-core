<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsInstanceSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateInstanceSecuritySettingsRequest;
use App\Services\OidcSettingsRepositoryService;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Authentication & security: password policy, login rate limit, lockout,
 * self-registration, required two-factor authentication and single sign-on (Signaculum or
 * another OIDC provider, through qiick/laravel-oidc). The client secret is write-only. Instance
 * admins only (SettingPolicy).
 */
final class InstanceSecuritySettingsController extends Controller
{
    use LogsInstanceSettingsUpdateTrait;

    public function __construct(
        private readonly SettingsRepositoryService $settings,
        private readonly OidcSettingsRepositoryService $oidcSettings,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('settings/instance/security', [
            'instance' => $this->settings->forEditOnly([
                'password_policy',
                'login_max_attempts',
                'login_lockout_threshold',
                'login_lockout_minutes',
                'registration_enabled',
                'two_factor_required',
                'oidc_enabled',
                'oidc_issuer',
                'oidc_client_id',
                'has_oidc_client_secret',
                'oidc_button_label',
                'oidc_jit_provisioning',
                'oidc_auto_link',
                'oidc_only',
                'oidc_role_mapping_enabled',
                'oidc_organization',
            ]),
            'passwordPolicies' => SettingsRepositoryService::PASSWORD_POLICIES,
            'oidcCallbackUrl' => $this->oidcSettings->callbackUrl(),
            'oidcBackChannelLogoutUrl' => route('oidc.backchannel-logout'),
        ]);
    }

    public function update(UpdateInstanceSecuritySettingsRequest $request): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
        $this->logInstanceSettingsUpdate($request, 'security', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('instance.security.edit');
    }
}
