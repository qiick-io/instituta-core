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
use App\Services\Search\PgsqlFullTextSearchDriver;
use App\Services\Search\SqliteFts5SearchDriver;
use App\Services\Search\UnsupportedSearchDriver;
use App\Services\SettingsRepositoryService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Spatie\Activitylog\Facades\Activity as ActivityFacade;
use Spatie\Permission\Models\Permission;

/**
 * Core application service provider: bindings, activity logging, defaults.
 */
class AppServiceProvider extends ServiceProvider
{
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
