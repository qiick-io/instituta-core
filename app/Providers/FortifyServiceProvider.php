<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\SsoLogoutResponse;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;
use Qiick\Oidc\Services\SsoOnlyModeService;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LogoutResponse::class, SsoLogoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Refuse inactive users on every local login path (password, two-factor and passkey), and in
     * SSO-only mode everyone but the break-glass accounts. Both callbacks bypass Auth::attempt(),
     * so the package's own SSO-only listener never sees them: they must ask it here.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', $request->input(Fortify::username()))->first();

            if ($user === null || ! $user->is_active || $user->password === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            return app(SsoOnlyModeService::class)->canUseLocalLogin($user) ? $user : null;
        });

        Passkeys::authorizeLoginUsing(
            fn (Request $request, PasskeyUser $user): bool => $user instanceof User
                && $user->is_active
                && app(SsoOnlyModeService::class)->canUseLocalLogin($user),
        );
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(function (Request $request) {
            $settings = app(SettingsRepositoryService::class);

            return Inertia::render('auth/login', [
                'canResetPassword' => Features::enabled(Features::resetPasswords()),
                'canRegister' => Features::enabled(Features::registration())
                    && $settings->isRegistrationEnabled(),
                'canManagePasskeys' => Features::canManagePasskeys(),
                'status' => $request->session()->get('status'),
                'oidc' => app(SsoOnlyModeService::class)->loginOptions($request)->toArray(),
            ]);
        });

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(function () {
            if (! Features::enabled(Features::registration())
                || ! app(SettingsRepositoryService::class)->isRegistrationEnabled()) {
                abort(403);
            }

            return Inertia::render('auth/register', [
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
            ]);
        });

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(function () {
            $canManagePasskeys = Features::canManagePasskeys();
            $user = auth()->user();

            return Inertia::render('auth/confirm-password', [
                'canManagePasskeys' => $canManagePasskeys,
                'hasPasskeys' => $canManagePasskeys
                    && $user !== null
                    && $user->hasPasskeysEnabled(),
            ]);
        });
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(app(SettingsRepositoryService::class)->loginMaxAttempts())->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
