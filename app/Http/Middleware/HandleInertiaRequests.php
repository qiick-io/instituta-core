<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AppVersionService;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\SessionPerimeterService;
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

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'appVersion' => app(AppVersionService::class)->current(),
            'auth' => [
                'user' => $user,
                // Only hides UI: the server still checks every request.
                'permissions' => $user instanceof User ? $permissionResolver->permissionsFor($user) : [],
                'roleNames' => $user instanceof User ? $permissionResolver->roleNamesFor($user) : [],
                'isSuperAdmin' => $user instanceof User && $permissionResolver->isSuperAdmin($user),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'instituta' => app(SessionPerimeterService::class)->sharedPayload($request),
        ];
    }
}
