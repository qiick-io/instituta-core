<?php

declare(strict_types=1);
use App\Models\User;

/*
 * qiick/laravel-oidc, the keys Instituta sets. Every key left out keeps the package default
 * (vendor/qiick/laravel-oidc/config/oidc.php); the merge is per top-level key, so a nested
 * array here is complete.
 *
 * The provider settings are not here: issuer, client id and secret, the button label,
 * just-in-time accounts, auto-link, SSO-only mode and role mapping live in the settings table
 * and are edited in Settings → Authentication & security, like every other instance setting
 * ({@see App\Services\OidcSettingsRepositoryService}). There are no `OIDC_*` env keys for them.
 */
return [

    /*
     * Where a successful and a failed SSO login land: Fortify's home and login page.
     */
    'home' => '/dashboard',

    'login_url' => '/login',

    'users' => [

        'model' => User::class,

        'table' => 'users',

        'email_column' => 'email',

        /*
         * Deactivated accounts can never sign in through SSO, as with a password.
         */
        'active_column' => 'is_active',

    ],

    /*
     * Signaculum organisation roles → Instituta roles, applied when role mapping is on in the
     * settings (which also names the organisation). `super-admin` is never granted or revoked by
     * the IdP, so the break-glass account stays local. Billing members get no role here.
     */
    'roles' => [

        'enabled' => false,

        'claim' => 'orgs',

        'organization' => null,

        'map' => [
            'owner' => 'admin',
            'admin' => 'admin',
            'member' => 'reader',
        ],

        'forbidden' => [],

    ],

    /*
     * Second factor. An SSO login replaces the password, never the second factor: a user with
     * two-factor authentication here passes its challenge after SSO too, whatever the id_token's
     * `amr` / `acr` say. The empty lists turn off the package default that would accept
     * Signaculum's MFA instead. Users without a second factor here are enrolled by
     * EnsureTwoFactorIsEnabledMiddleware when the settings require 2FA, as after a password login.
     *
     * ponytail: Signaculum's MFA claim is not trusted yet (signaculum-core#163, #174 are open);
     * upgrade path: list `mfa` / `urn:qiick:loa:mfa` here and teach the middleware that an SSO
     * session with proven MFA satisfies the 2FA requirement.
     */
    'mfa' => [

        'required' => true,

        'amr_values' => [],

        'acr_values' => [],

        'request_acr_values' => null,

    ],

];
