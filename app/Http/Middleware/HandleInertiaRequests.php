<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\User;
use App\Services\AppVersionService;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\ReviewCountsService;
use App\Services\SessionPerimeterService;
use App\Services\SettingsRepositoryService;
use App\Services\ThemePresetService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $permissionResolver = app(EffectivePermissionResolverService::class);
        $settings = app(SettingsRepositoryService::class);
        $theme = app(ThemePresetService::class)->current();

        return [
            ...parent::share($request),
            'name' => $settings->appName(),
            'appVersion' => app(AppVersionService::class)->current(),
            'auth' => [
                // Never expose the bigint primary key; shared `id` is the public UUID.
                'user' => $user instanceof User
                    ? array_merge($user->makeHidden(['id'])->toArray(), ['id' => $user->public_id])
                    : $user,
                // Only hides UI: the server still checks every request.
                'permissions' => $user instanceof User ? $permissionResolver->permissionsFor($user) : [],
                'roleNames' => $user instanceof User ? $permissionResolver->roleNamesFor($user) : [],
                'isSuperAdmin' => $user instanceof User && $permissionResolver->isSuperAdmin($user),
                'canManageSettings' => (bool) $user?->can('update', Setting::class),
            ],
            'locale' => app()->getLocale(),
            'availableLocales' => config('i18n.available_locales', ['en' => 'English']),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'instituta' => app(SessionPerimeterService::class)->sharedPayload($request),
            // What waits for review in the session scope (#255): sidebar badge and Review tabs.
            'review' => fn (): array => $user instanceof User
                ? app(ReviewCountsService::class)->counts(app(SessionPerimeterService::class)->optionalPerimeter($request))
                : ['open_proposals' => 0, 'open_gaps' => 0],
            'instanceSettings' => [
                'reportBugUrl' => $settings->reportBugUrl(),
            ],
            'notifications' => [
                'unread_count' => $user instanceof User
                    ? $user->unreadNotifications()->count()
                    : 0,
                'sound_enabled' => $user instanceof User
                    ? (bool) $user->notification_sound_enabled
                    : false,
            ],
            'projectAppearance' => [
                'themePreset' => $theme['themePreset'],
                'themeCss' => [
                    'light' => $theme['light'],
                    'dark' => $theme['dark'],
                    'brandPrimary' => $theme['brandPrimary'],
                    'brandPrimaryDark' => $theme['brandPrimaryDark'],
                    'brandPrimaryForeground' => $theme['brandPrimaryForeground'],
                    'brandPrimaryDarkForeground' => $theme['brandPrimaryDarkForeground'],
                ],
            ],
            'flash' => [
                'success' => fn (): mixed => $request->session()->get('success'),
                'error' => fn (): mixed => $request->session()->get('error'),
                'warning' => fn (): mixed => $request->session()->get('warning'),
            ],
        ];
    }
}
