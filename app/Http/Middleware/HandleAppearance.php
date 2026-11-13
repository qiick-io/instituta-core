<?php

namespace App\Http\Middleware;

use App\Services\SettingsRepositoryService;
use App\Services\ThemePresetService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Share appearance cookie / instance default and theme CSS with app.blade.php.
 */
class HandleAppearance
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(SettingsRepositoryService::class);
        $appearanceCookie = $request->cookie('appearance');

        View::share(
            'appearance',
            in_array($appearanceCookie, SettingsRepositoryService::APPEARANCES, true)
                ? $appearanceCookie
                : $settings->defaultAppearance(),
        );
        View::share('projectThemeCss', app(ThemePresetService::class)->current());

        return $next($request);
    }
}
