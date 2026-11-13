<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SearchIndexContract;
use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\User;
use App\Observers\ItemObserver;
use App\Observers\ItemSectionObserver;
use App\Services\ActivityEventLoggerService;
use App\Services\ActivityOrganisationResolverService;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Search\PgsqlFullTextSearchDriver;
use App\Services\Search\SqliteFts5SearchDriver;
use App\Services\Search\UnsupportedSearchDriver;
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
        Gate::before(function (?User $user, string $ability, array $arguments): ?bool {
            if ($user === null) {
                return null;
            }

            if ($arguments !== [] && Gate::getPolicyFor($arguments[0]) !== null) {
                return null;
            }

            if (app(EffectivePermissionResolverService::class)->isSuperAdmin($user)) {
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

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
