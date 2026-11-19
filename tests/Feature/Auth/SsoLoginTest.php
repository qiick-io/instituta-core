<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Qiick\Oidc\Models\UserIdentity;
use Qiick\Oidc\Testing\FakeIdentityProvider;
use Tests\TestCase;

/*
 * SSO through qiick/laravel-oidc with Instituta's contracts, against the package's in-memory
 * identity provider. The fake also turns on `config('oidc.*')`; Instituta must ignore that and
 * follow the settings table, which the 404 tests below prove.
 */

const SSO_FAILED_MESSAGE = 'SSO login failed. Try again or use email and password.';

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->identityProvider = FakeIdentityProvider::fake(issuer: 'https://id.example.com', clientId: 'instituta');
});

/**
 * Saves the SSO settings an administrator would enter for the fake identity provider.
 *
 * @param  array<string, mixed>  $overrides
 */
function saveSsoSettings(FakeIdentityProvider $identityProvider, array $overrides = []): void
{
    app(SettingsRepositoryService::class)->save([
        'oidc_enabled' => true,
        'oidc_issuer' => $identityProvider->issuer,
        'oidc_client_id' => $identityProvider->clientId,
        'oidc_client_secret' => $identityProvider->clientSecret,
        ...$overrides,
    ]);
}

/**
 * The browser's trip: SSO button, login at the IdP with these id_token claims, callback.
 *
 * @param  array<string, mixed>  $claims
 */
function signInThroughSso(TestCase $testCase, FakeIdentityProvider $identityProvider, array $claims = []): TestResponse
{
    $authorizationUrl = (string) $testCase->get(route('oidc.redirect'))->headers->get('Location');

    return $testCase->get($identityProvider->authorize($authorizationUrl, $claims));
}

/**
 * Starts a login and returns the callback URL and the nonce the app sent to the IdP.
 *
 * @return array{callbackUrl: string, nonce: string}
 */
function startSsoLogin(TestCase $testCase, FakeIdentityProvider $identityProvider): array
{
    $authorizationUrl = (string) $testCase->get(route('oidc.redirect'))->headers->get('Location');
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationParameters);

    return [
        'callbackUrl' => $identityProvider->authorize($authorizationUrl),
        'nonce' => (string) $authorizationParameters['nonce'],
    ];
}

function assertSsoLoginRefused(TestCase $testCase, TestResponse $response, string $message = SSO_FAILED_MESSAGE): void
{
    $response->assertRedirect('/login')->assertSessionHasErrors(['email' => $message]);
    $testCase->assertGuest();
}

function ssoUser(array $attributes = []): User
{
    return User::factory()->create(['email' => 'sso.user@example.com', ...$attributes]);
}

function linkSsoIdentity(User $user, FakeIdentityProvider $identityProvider, string $subject = 'fake-subject-1'): UserIdentity
{
    return UserIdentity::query()->create([
        'user_id' => $user->id,
        'issuer' => $identityProvider->issuer,
        'subject' => $subject,
    ]);
}

describe('fails closed', function (): void {
    // JSON requests: for a browser, RenderHttpErrorResponse turns a guest's 404 into a redirect
    // to the login page, which would hide whether the package's gate answered at all.
    it('answers 404 on all three SSO routes while SSO is off in the settings', function (array $settings): void {
        saveSsoSettings($this->identityProvider, $settings);

        $this->getJson(route('oidc.redirect'))->assertNotFound();
        $this->getJson(route('oidc.callback', ['code' => 'code', 'state' => 'state']))->assertNotFound();
        $this->postJson(route('oidc.backchannel-logout'), [
            'logout_token' => $this->identityProvider->sign($this->identityProvider->logoutTokenClaims()),
        ])->assertNotFound();
    })->with([
        'switched off' => [['oidc_enabled' => false]],
        'on without issuer' => [['oidc_issuer' => null]],
        'on without client id' => [['oidc_client_id' => null]],
    ]);

    it('answers 404 on all three SSO routes on a fresh install, although config says SSO is on', function (): void {
        expect(config('oidc.enabled'))->toBeTrue();

        $this->getJson(route('oidc.redirect'))->assertNotFound();
        $this->getJson(route('oidc.callback'))->assertNotFound();
        $this->postJson(route('oidc.backchannel-logout'))->assertNotFound();
    });

    it('refuses a callback with another state than the one it sent', function (): void {
        saveSsoSettings($this->identityProvider);
        linkSsoIdentity(ssoUser(), $this->identityProvider);
        ['callbackUrl' => $callbackUrl] = startSsoLogin($this, $this->identityProvider);

        $forgedCallbackUrl = preg_replace('/state=[^&]+/', 'state=forged-state', $callbackUrl);

        assertSsoLoginRefused($this, $this->get((string) $forgedCallbackUrl));
        expect($this->identityProvider->tokenRequestCount)->toBe(0);
    });

    it('refuses an id_token whose nonce belongs to another login', function (): void {
        saveSsoSettings($this->identityProvider);
        linkSsoIdentity(ssoUser(), $this->identityProvider);
        $identityProvider = $this->identityProvider;
        $identityProvider->modifyTokenResponseUsing(fn (array $tokenResponse): array => [
            ...$tokenResponse,
            'id_token' => $identityProvider->sign($identityProvider->idTokenClaims(['nonce' => 'nonce-of-another-login'])),
        ]);

        assertSsoLoginRefused($this, signInThroughSso($this, $identityProvider));
    });

    it('refuses an id_token signed by a key the identity provider does not publish', function (): void {
        saveSsoSettings($this->identityProvider);
        linkSsoIdentity(ssoUser(), $this->identityProvider);
        $identityProvider = $this->identityProvider;
        ['callbackUrl' => $callbackUrl, 'nonce' => $nonce] = startSsoLogin($this, $identityProvider);
        $foreignKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $publishedKeyId = $identityProvider->rotateSigningKey();

        // Same `kid` as a published key and the right nonce: only the signature is wrong.
        $identityProvider->modifyTokenResponseUsing(fn (array $tokenResponse): array => [
            ...$tokenResponse,
            'id_token' => $identityProvider->sign($identityProvider->idTokenClaims(['nonce' => $nonce]), keyId: $publishedKeyId, privateKey: $foreignKey),
        ]);

        assertSsoLoginRefused($this, $this->get($callbackUrl));
    });

    it('never creates or links an account from an unverified email', function (array $settings, Closure $arrangeExistingUser): void {
        saveSsoSettings($this->identityProvider, $settings);
        $arrangeExistingUser();

        assertSsoLoginRefused(
            $this,
            signInThroughSso($this, $this->identityProvider, ['email_verified' => false]),
            'SSO login requires a verified email from the identity provider.',
        );
        expect(UserIdentity::query()->count())->toBe(0)
            ->and(User::query()->where('email', 'sso.user@example.com')->whereHas('roles')->exists())->toBeFalse();
    })->with([
        'just-in-time account' => [['oidc_jit_provisioning' => true], fn () => null],
        'auto-link to an existing account' => [['oidc_auto_link' => true], fn () => ssoUser()],
    ]);

    it('refuses an unknown identity while just-in-time accounts and auto-link are off', function (): void {
        saveSsoSettings($this->identityProvider);
        ssoUser();

        assertSsoLoginRefused(
            $this,
            signInThroughSso($this, $this->identityProvider),
            'No local account matches this SSO login. Ask an admin to create your user first.',
        );
    });

    it('refuses a deactivated account', function (): void {
        saveSsoSettings($this->identityProvider);
        linkSsoIdentity(ssoUser(['is_active' => false]), $this->identityProvider);

        assertSsoLoginRefused(
            $this,
            signInThroughSso($this, $this->identityProvider),
            'This account is inactive.',
        );
    });
});

describe('signs in', function (): void {
    it('creates the account on the first login and reuses it on the next', function (): void {
        saveSsoSettings($this->identityProvider, ['oidc_jit_provisioning' => true]);
        $claims = ['sub' => 'signaculum-ada', 'email' => 'ada@example.com', 'given_name' => 'Ada', 'family_name' => 'Lovelace'];

        signInThroughSso($this, $this->identityProvider, $claims)->assertRedirect('/dashboard');

        $user = User::query()->sole();
        expect($user->only(['first_name', 'last_name', 'email', 'is_active']))
            ->toBe(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'is_active' => true])
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->getRoleNames()->all())->toBe([RoleEnum::Reader->value]);
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'));
        signInThroughSso($this, $this->identityProvider, $claims)->assertRedirect('/dashboard');

        expect(User::query()->count())->toBe(1)
            ->and(UserIdentity::query()->sole()->only(['user_id', 'issuer', 'subject']))
            ->toBe(['user_id' => $user->id, 'issuer' => 'https://id.example.com', 'subject' => 'signaculum-ada']);
        $this->assertAuthenticatedAs($user);
    });

    it('signs in the account linked to the identity', function (): void {
        saveSsoSettings($this->identityProvider);
        $user = ssoUser();
        linkSsoIdentity($user, $this->identityProvider);

        signInThroughSso($this, $this->identityProvider)->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    });

    it('offers the SSO button on the login page only while SSO is on', function (): void {
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->where('oidc.isSsoAvailable', false)
            ->where('oidc.redirectUrl', null));

        saveSsoSettings($this->identityProvider, ['oidc_button_label' => 'Sign in with Signaculum']);

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->where('oidc.isSsoAvailable', true)
            ->where('oidc.isPasswordLoginVisible', true)
            ->where('oidc.buttonLabel', 'Sign in with Signaculum')
            ->where('oidc.redirectUrl', route('oidc.redirect')));
    });
});

describe('second factor', function (): void {
    it('runs the local two-factor challenge after SSO, even when the id_token claims MFA', function (array $claims): void {
        saveSsoSettings($this->identityProvider);
        linkSsoIdentity(User::factory()->withTwoFactor()->create(['email' => 'sso.user@example.com']), $this->identityProvider);

        signInThroughSso($this, $this->identityProvider, $claims)->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    })->with([
        'amr mfa' => [['amr' => ['pwd', 'otp', 'mfa']]],
        'acr loa mfa' => [['acr' => 'urn:qiick:loa:mfa']],
        'password only' => [['amr' => ['pwd']]],
    ]);

    it('signs in a user without a local second factor, as a password login would', function (): void {
        saveSsoSettings($this->identityProvider);
        $user = ssoUser();
        linkSsoIdentity($user, $this->identityProvider);

        signInThroughSso($this, $this->identityProvider, ['amr' => ['pwd']])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    });
});

describe('logout', function (): void {
    it('ends the Instituta session when Signaculum logs the session out over the back channel', function (): void {
        saveSsoSettings($this->identityProvider);
        $user = ssoUser();
        linkSsoIdentity($user, $this->identityProvider);
        signInThroughSso($this, $this->identityProvider, ['sid' => 'signaculum-session-1']);
        $this->get(route('dashboard'))->assertOk();

        $this->post(route('oidc.backchannel-logout'), [
            'logout_token' => $this->identityProvider->sign($this->identityProvider->logoutTokenClaims(['sid' => 'signaculum-session-1'])),
        ])->assertOk();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    });

    it('refuses a back-channel logout token signed by a key the identity provider does not publish', function (): void {
        saveSsoSettings($this->identityProvider);
        linkSsoIdentity(ssoUser(), $this->identityProvider);
        signInThroughSso($this, $this->identityProvider);
        $foreignKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $this->post(route('oidc.backchannel-logout'), [
            'logout_token' => $this->identityProvider->sign($this->identityProvider->logoutTokenClaims(), privateKey: $foreignKey),
        ])->assertBadRequest();

        $this->get(route('dashboard'))->assertOk();
    });

    it('ends the Signaculum session too when an SSO user logs out', function (): void {
        saveSsoSettings($this->identityProvider);
        linkSsoIdentity(ssoUser(), $this->identityProvider);
        signInThroughSso($this, $this->identityProvider);

        $response = $this->post(route('logout'));

        expect($response->headers->get('Location'))
            ->toStartWith('https://id.example.com/protocol/openid-connect/logout?id_token_hint=')
            ->toContain('post_logout_redirect_uri='.rawurlencode(url('/')));
        $this->assertGuest();
    });

    it('keeps the local logout for a password login', function (): void {
        saveSsoSettings($this->identityProvider);

        $this->actingAs(ssoUser())->post(route('logout'))->assertRedirect('/');

        $this->assertGuest();
    });
});

describe('SSO-only mode', function (): void {
    it('refuses password logins, except for a super-admin with two-factor authentication', function (): void {
        saveSsoSettings($this->identityProvider, ['oidc_only' => true]);
        $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
        $superAdminWithoutSecondFactor = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
        $breakGlassAccount = User::factory()->withTwoFactor()->create()->assignRole(RoleEnum::SuperAdmin->value);

        foreach ([$admin, $superAdminWithoutSecondFactor] as $refusedUser) {
            $this->post(route('login.store'), ['email' => $refusedUser->email, 'password' => 'password'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }

        $this->post(route('login.store'), ['email' => $breakGlassAccount->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));
        expect(session('login.id'))->toBe($breakGlassAccount->id);
    });

    it('hides the password form unless the break-glass parameter asks for it', function (): void {
        saveSsoSettings($this->identityProvider, ['oidc_only' => true]);

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->where('oidc.isSsoOnly', true)
            ->where('oidc.isPasswordLoginVisible', false));
        $this->get(route('login', ['local' => 1]))->assertInertia(fn (Assert $page) => $page
            ->where('oidc.isPasswordLoginVisible', true));
    });

    it('restores local login for everyone when SSO is switched off', function (): void {
        saveSsoSettings($this->identityProvider, ['oidc_only' => true, 'oidc_enabled' => false]);
        $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);

        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($admin);
    });
});

describe('role mapping', function (): void {
    it('applies the organisation roles of the orgs claim and never touches super-admin', function (): void {
        saveSsoSettings($this->identityProvider, ['oidc_role_mapping_enabled' => true, 'oidc_organization' => 'acme']);
        $user = ssoUser()->assignRole(RoleEnum::Reader->value, RoleEnum::SuperAdmin->value);
        linkSsoIdentity($user, $this->identityProvider);
        $authorizationUrl = (string) $this->get(route('oidc.redirect'))->headers->get('Location');
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationParameters);

        $this->get($this->identityProvider->authorize($authorizationUrl, ['orgs' => [
            ['id' => 'org-1', 'slug' => 'acme', 'roles' => ['owner']],
            ['id' => 'org-2', 'slug' => 'other', 'roles' => ['member']],
        ]]))->assertRedirect('/dashboard');

        expect(explode(' ', (string) $authorizationParameters['scope']))->toContain('qiick.orgs')
            ->and($user->refresh()->getRoleNames()->sort()->values()->all())
            ->toBe([RoleEnum::Admin->value, RoleEnum::SuperAdmin->value]);
    });

    it('does not ask for the organisations scope while role mapping is off', function (): void {
        saveSsoSettings($this->identityProvider);

        $authorizationUrl = (string) $this->get(route('oidc.redirect'))->headers->get('Location');
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationParameters);

        expect(explode(' ', (string) $authorizationParameters['scope']))->toBe(['openid', 'profile', 'email']);
    });
});

describe('oidc:configure', function (): void {
    it('checks the provider saved in the settings and passes', function (): void {
        saveSsoSettings($this->identityProvider);

        $exitCode = Artisan::call('oidc:configure', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(collect($report['checks'])->firstWhere('check', 'settings')['detail'])
            ->toBe('Issuer https://id.example.com, client instituta.');
    });
});
