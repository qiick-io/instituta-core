<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SearchIndexContract;
use App\Enums\PermissionEnum;
use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\ItemObserver;
use App\Observers\ItemSectionObserver;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\SettingPolicy;
use App\Policies\UserGroupPolicy;
use App\Services\ActivityEventLoggerService;
use App\Services\ActivityOrganisationResolverService;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\BreakGlassPolicyService;
use App\Services\OidcSettingsRepositoryService;
use App\Services\Search\PgsqlFullTextSearchDriver;
use App\Services\Search\SqliteFts5SearchDriver;
use App\Services\Search\UnsupportedSearchDriver;
use App\Services\SettingsRepositoryService;
use App\Services\SsoUserProvisioningService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Qiick\Oidc\Contracts\BreakGlassPolicyContract;
use Qiick\Oidc\Contracts\ProviderConfigurationRepositoryContract;
use Qiick\Oidc\Contracts\UserProvisioningContract;
use Qiick\Oidc\Exceptions\IdentityProviderRequestException;
use Qiick\Oidc\Services\RoleMappingService;
use Qiick\Oidc\Services\RpInitiatedLogoutService;
use Qiick\Oidc\Services\SsoOnlyModeService;
use Qiick\Oidc\Services\UserLinkingService;
use Spatie\Activitylog\Facades\Activity as ActivityFacade;
use Spatie\Permission\Models\Permission;

/**
 * Core application service provider: bindings, activity logging, defaults.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Request attribute with the identity provider's logout URL of an SSO session that just
     * logged out (null for a local login), read by SsoLogoutResponse.
     */
    public const string IDENTITY_PROVIDER_LOGOUT_URL_ATTRIBUTE = 'oidc_end_session_url';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsRepositoryService::class);

        $this->app->bind(SearchIndexContract::class, function (): SearchIndexContract {
            return match (DB::connection()->getDriverName()) {
                'pgsql' => new PgsqlFullTextSearchDriver,
                'sqlite' => new SqliteFts5SearchDriver,
                default => new UnsupportedSearchDriver,
            };
        });

        $this->registerSingleSignOn();
    }

    /**
     * qiick/laravel-oidc with Instituta's contracts: settings from the settings table, the
     * break-glass policy and JIT accounts with first and last name. The package provider is
     * auto-discovered and registered first, so these bindings replace its defaults.
     *
     * Role synchronisation keeps the package's Spatie default (Instituta roles are Spatie
     * roles) and the second factor keeps the Fortify default, configured in `config/oidc.php`.
     */
    protected function registerSingleSignOn(): void
    {
        $this->app->bind(ProviderConfigurationRepositoryContract::class, OidcSettingsRepositoryService::class);
        $this->app->bind(BreakGlassPolicyContract::class, BreakGlassPolicyService::class);
        $this->app->bind(UserProvisioningContract::class, SsoUserProvisioningService::class);

        foreach ([UserLinkingService::class, SsoOnlyModeService::class, RoleMappingService::class] as $settingsDrivenService) {
            $this->app->beforeResolving($settingsDrivenService, function (): void {
                app(OidcSettingsRepositoryService::class)->applyToConfig(app(ConfigRepository::class));
            });
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configureActivityLogging();
        $this->registerAuthActivityListeners();
        $this->registerTwoFactorActivityListeners();

        Item::observe(ItemObserver::class);
        ItemSection::observe(ItemSectionObserver::class);
    }

    /**
     * Super-admin bypass for permission-name and string abilities only. Model policies
     * decide for themselves (they keep invariants that bind super-admins too).
     */
    protected function configureAuthorization(): void
    {
        // Spatie models live outside App\Models; bind policies explicitly (excubia-app).
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(UserGroup::class, UserGroupPolicy::class);

        Gate::before(function (?User $user, string $ability, array $arguments): ?bool {
            if ($user === null) {
                return null;
            }

            if ($arguments !== [] && Gate::getPolicyFor($arguments[0]) !== null) {
                return null;
            }

            $permissionResolver = app(EffectivePermissionResolverService::class);

            if ($permissionResolver->isSuperAdmin($user)) {
                return true;
            }

            // Spatie's own check sees direct roles only; permissions inherited through a user
            // group pass here, so `$user->can('can-…')` in policies matches the route middleware.
            if ($arguments === [] && PermissionEnum::tryFrom($ability) !== null && $permissionResolver->hasPermission($user, $ability)) {
                return true;
            }

            return null;
        });
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('instituta-api', function (Request $request) {
            $key = $request->attributes->get('instituta.api_key');
            $id = $key instanceof ApiKey ? $key->id : ($request->ip() ?? 'anonymous');
            $perMinute = max(1, (int) config('instituta.mcp.rate_limit_per_minute', 60));

            return Limit::perMinute($perMinute)->by((string) $id);
        });
    }

    protected function configureActivityLogging(): void
    {
        ActivityFacade::beforeLogging(function (Activity $activity): void {
            $properties = collect($activity->properties ?? []);
            $organisationId = app(ActivityOrganisationResolverService::class)->resolve(
                $activity->subject,
                $properties->all(),
            );

            if ($organisationId !== null) {
                $activity->organisation_id = $organisationId;
            }

            $activity->properties = $properties->merge([
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'organisation_id' => $organisationId ?? $properties->get('organisation_id'),
            ]);
        });
    }

    protected function registerAuthActivityListeners(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            activity()
                ->causedBy($event->user)
                ->useLog('auth')
                ->event('login')
                ->log('User logged in');
        });

        Event::listen(Logout::class, function (Logout $event): void {
            $this->rememberIdentityProviderLogoutUrl(request());

            if (! $event->user instanceof User) {
                return;
            }

            activity()
                ->causedBy($event->user)
                ->useLog('auth')
                ->event('logout')
                ->log('User logged out');
        });

        Event::listen(Failed::class, function (Failed $event): void {
            $identifier = $event->credentials['email']
                ?? $event->credentials['username']
                ?? null;

            activity()
                ->causedByAnonymous()
                ->useLog('auth')
                ->event('failed')
                ->withProperties([
                    'identifier' => is_string($identifier) ? $identifier : null,
                ])
                ->log('Failed login attempt');
        });
    }

    /**
     * Keeps the identity provider's logout URL of an SSO session for SsoLogoutResponse. It must
     * be read here: the session still holds the SSO identity, and Fortify invalidates it right
     * after the Logout event. An unreachable IdP never blocks the local logout.
     */
    protected function rememberIdentityProviderLogoutUrl(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        try {
            $endSessionUrl = app(RpInitiatedLogoutService::class)->endSessionUrl($request->session());
        } catch (IdentityProviderRequestException $exception) {
            Log::warning('SSO logout: the identity provider could not be reached, only the local session ends.', [
                'exception' => $exception->getMessage(),
            ]);
            $endSessionUrl = null;
        }

        $request->attributes->set(self::IDENTITY_PROVIDER_LOGOUT_URL_ATTRIBUTE, $endSessionUrl);
    }

    protected function registerTwoFactorActivityListeners(): void
    {
        Event::listen(TwoFactorAuthenticationEnabled::class, function (TwoFactorAuthenticationEnabled $event): void {
            app(ActivityEventLoggerService::class)->twoFactorEnabled($event->user);
        });

        Event::listen(TwoFactorAuthenticationDisabled::class, function (TwoFactorAuthenticationDisabled $event): void {
            app(ActivityEventLoggerService::class)->twoFactorDisabled($event->user);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::shouldBeStrict(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => app(SettingsRepositoryService::class)->passwordRule());
    }
}
