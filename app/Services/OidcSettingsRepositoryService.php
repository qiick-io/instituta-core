<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Qiick\Oidc\Contracts\ProviderConfigurationRepositoryContract;
use Qiick\Oidc\Data\ProviderConfigurationData;
use Qiick\Oidc\Exceptions\OidcNotConfiguredException;
use Qiick\Oidc\Repositories\ConfigProviderConfigurationRepository;

/**
 * The SSO provider settings from the settings table, for qiick/laravel-oidc. Every instance
 * setting is edited in the admin UI, so SSO is too: nothing is read from `OIDC_*` env keys.
 *
 * isEnabled() is the gate of all three package routes (redirect, callback, back-channel
 * logout): they answer 404 unless an administrator turned SSO on and saved an issuer and a
 * client id. A missing secret is a public client, which the package runs with PKCE alone.
 *
 * The package reads its login behaviour (JIT, auto-link, SSO-only, button label, role mapping)
 * from `config('oidc.*')` when it builds its services; applyToConfig() copies the settings there
 * first (AppServiceProvider calls it before those services are resolved).
 */
final readonly class OidcSettingsRepositoryService implements ProviderConfigurationRepositoryContract
{
    /**
     * Signaculum's scope that adds the `orgs` claim the role mapping reads.
     */
    public const string ORGANISATIONS_SCOPE = 'qiick.orgs';

    public function __construct(
        private SettingsRepositoryService $settings,
        private UrlGenerator $urlGenerator,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings->isOidcEnabled()
            && $this->settings->oidcIssuer() !== null
            && $this->settings->oidcClientId() !== null;
    }

    /**
     * @throws OidcNotConfiguredException when isEnabled() is false
     */
    public function configuration(): ProviderConfigurationData
    {
        if (! $this->isEnabled()) {
            throw new OidcNotConfiguredException('SSO is off, or the issuer or client id is missing in Settings → Authentication & security.');
        }

        return new ProviderConfigurationData(
            issuer: (string) $this->settings->oidcIssuer(),
            clientId: (string) $this->settings->oidcClientId(),
            clientSecret: $this->settings->oidcClientSecret(),
            redirectUri: $this->callbackUrl(),
            scopes: $this->scopes(),
            postLogoutRedirectUri: $this->urlGenerator->to('/'),
        );
    }

    /**
     * The redirect URI to register at the identity provider.
     */
    public function callbackUrl(): string
    {
        return $this->urlGenerator->to(ConfigProviderConfigurationRepository::DEFAULT_CALLBACK_PATH);
    }

    /**
     * Copies the login behaviour settings into `config('oidc.*')`, where the package reads them.
     */
    public function applyToConfig(ConfigRepository $config): void
    {
        $config->set([
            'oidc.button_label' => $this->settings->oidcButtonLabel(),
            'oidc.jit' => $this->settings->isOidcJitProvisioningEnabled(),
            'oidc.auto_link' => $this->settings->isOidcAutoLinkEnabled(),
            'oidc.only' => $this->settings->isOidcOnly(),
            'oidc.roles.enabled' => $this->settings->isOidcRoleMappingEnabled(),
            'oidc.roles.organization' => $this->settings->oidcOrganization(),
        ]);
    }

    /**
     * `qiick.orgs` only with role mapping: another IdP may refuse a scope it does not know.
     *
     * @return list<string>
     */
    private function scopes(): array
    {
        $scopes = ['openid', 'profile', 'email'];

        if ($this->settings->isOidcRoleMappingEnabled()) {
            $scopes[] = self::ORGANISATIONS_SCOPE;
        }

        return $scopes;
    }
}
