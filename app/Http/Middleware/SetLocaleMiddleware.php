<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SettingsRepositoryService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the request locale from the signed-in user, then the `locale` cookie, then
 * the instance default language, then the app config.
 */
final class SetLocaleMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $availableLocales = array_keys((array) config('i18n.available_locales', ['en' => 'English']));
        $authenticatedUser = $request->user();
        $userLocale = is_object($authenticatedUser) ? $authenticatedUser->locale : null;

        foreach ([$userLocale, $request->cookie('locale')] as $candidateLocale) {
            if (is_string($candidateLocale) && in_array($candidateLocale, $availableLocales, true)) {
                return $candidateLocale;
            }
        }

        $instanceLocale = app(SettingsRepositoryService::class)->defaultLanguage();

        if (in_array($instanceLocale, $availableLocales, true)) {
            return $instanceLocale;
        }

        $appLocale = (string) config('app.locale', 'en');

        return in_array($appLocale, $availableLocales, true) ? $appLocale : 'en';
    }
}
