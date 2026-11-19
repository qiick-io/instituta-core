<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Qiick\Oidc\Contracts\ProviderConfigurationRepositoryContract;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->manager = User::factory()->create()->assignRole(RoleEnum::Admin->value);
});

/**
 * The security settings form with SSO turned on for Signaculum.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ssoSettingsPayload(array $overrides = []): array
{
    return [
        'password_policy' => 'medium',
        'login_max_attempts' => 5,
        'login_lockout_threshold' => 5,
        'login_lockout_minutes' => 15,
        'registration_enabled' => '0',
        'two_factor_required' => '0',
        'oidc_enabled' => '1',
        'oidc_issuer' => 'https://id.example.com',
        'oidc_client_id' => 'instituta',
        'oidc_client_secret' => 'client-secret-from-signaculum',
        'oidc_button_label' => 'Sign in with Signaculum',
        'oidc_jit_provisioning' => '1',
        'oidc_auto_link' => '0',
        'oidc_only' => '0',
        'oidc_role_mapping_enabled' => '1',
        'oidc_organization' => 'acme',
        ...$overrides,
    ];
}

it('saves the SSO settings and the package reads its provider from them', function (): void {
    $this->actingAs($this->manager)
        ->put(route('instance.security.update'), ssoSettingsPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('instance.security.edit'));

    $configuration = app(ProviderConfigurationRepositoryContract::class)->configuration();

    expect($configuration->issuer)->toBe('https://id.example.com')
        ->and($configuration->clientId)->toBe('instituta')
        ->and($configuration->clientSecret)->toBe('client-secret-from-signaculum')
        ->and($configuration->redirectUri)->toBe(url('auth/oidc/callback'))
        ->and($configuration->scopes)->toBe(['openid', 'profile', 'email', 'qiick.orgs']);
});

it('stores the client secret encrypted and never sends it to the page', function (): void {
    $this->actingAs($this->manager)->put(route('instance.security.update'), ssoSettingsPayload());

    $storedSecret = Setting::query()->where('key', 'oidc_client_secret')->value('value');
    expect($storedSecret)->toBeString()->not->toContain('client-secret-from-signaculum');

    $this->get(route('instance.security.edit'))
        ->assertDontSee('client-secret-from-signaculum')
        ->assertInertia(fn (Assert $page) => $page
            ->where('instance.has_oidc_client_secret', true)
            ->missing('instance.oidc_client_secret')
            ->where('oidcCallbackUrl', url('auth/oidc/callback'))
            ->where('oidcBackChannelLogoutUrl', route('oidc.backchannel-logout')));
});

it('keeps the stored client secret when the form leaves it blank', function (): void {
    $this->actingAs($this->manager)->put(route('instance.security.update'), ssoSettingsPayload());
    $this->put(route('instance.security.update'), ssoSettingsPayload(['oidc_client_secret' => '']));

    app()->forgetInstance(SettingsRepositoryService::class);

    expect(app(SettingsRepositoryService::class)->oidcClientSecret())->toBe('client-secret-from-signaculum');
});

it('validates the SSO settings', function (array $overrides, string $field, string $message): void {
    $this->actingAs($this->manager)
        ->put(route('instance.security.update'), ssoSettingsPayload($overrides))
        ->assertSessionHasErrors([$field => $message]);

    expect(app(SettingsRepositoryService::class)->isOidcEnabled())->toBeFalse();
})->with([
    'issuer missing' => [['oidc_issuer' => ''], 'oidc_issuer', 'The issuer URL field is required when SSO login is accepted.'],
    'issuer not a URL' => [['oidc_issuer' => 'id.example.com'], 'oidc_issuer', 'The issuer URL field must be a valid URL.'],
    'client id missing' => [['oidc_client_id' => ''], 'oidc_client_id', 'The client ID field is required when SSO login is accepted.'],
    'role mapping without organisation' => [['oidc_organization' => ''], 'oidc_organization', 'The Signaculum organisation field is required when role sync is accepted.'],
]);

it('refuses SSO-only mode while no super-admin with two-factor authentication could still sign in', function (): void {
    User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    User::factory()->withTwoFactor()->inactive()->create()->assignRole(RoleEnum::SuperAdmin->value);
    User::factory()->withTwoFactor()->create()->assignRole(RoleEnum::Admin->value);

    $this->actingAs($this->manager)
        ->put(route('instance.security.update'), ssoSettingsPayload(['oidc_only' => '1']))
        ->assertSessionHasErrors(['oidc_only' => 'SSO-only mode needs an active super-admin with two-factor authentication, who can still sign in when the identity provider is down.']);

    expect(app(SettingsRepositoryService::class)->isOidcOnly())->toBeFalse();
});

it('refuses SSO-only mode while self-registration is open', function (): void {
    User::factory()->withTwoFactor()->create()->assignRole(RoleEnum::SuperAdmin->value);

    $this->actingAs($this->manager)
        ->put(route('instance.security.update'), ssoSettingsPayload(['oidc_only' => '1', 'registration_enabled' => '1']))
        ->assertSessionHasErrors(['oidc_only' => 'Close self-registration before turning on SSO-only mode.']);
});

it('turns on SSO-only mode once a break-glass account exists', function (): void {
    User::factory()->withTwoFactor()->create()->assignRole(RoleEnum::SuperAdmin->value);

    $this->actingAs($this->manager)
        ->put(route('instance.security.update'), ssoSettingsPayload(['oidc_only' => '1']))
        ->assertSessionHasNoErrors();

    expect(app(SettingsRepositoryService::class)->isOidcOnly())->toBeTrue();
});
