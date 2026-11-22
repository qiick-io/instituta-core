<?php

use App\Http\Middleware\AddContractErrorCodeMiddleware;
use App\Http\Middleware\EnsureTwoFactorIsEnabledMiddleware;
use App\Http\Middleware\EnsureUserHasPermissionMiddleware;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocaleMiddleware;
use App\Http\Middleware\SetPermissionsTeamFromOrganisationMiddleware;
use App\Http\Middleware\SetSecurityHeadersMiddleware;
use App\Http\Responses\RenderHttpErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
        then: function (): void {
            require __DIR__.'/../routes/health.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale']);

        $middleware->alias([
            'permission' => EnsureUserHasPermissionMiddleware::class,
        ]);

        // Global so unmatched API routes and rendered exceptions get their contract code too (#227).
        $middleware->append(AddContractErrorCodeMiddleware::class);

        $middleware->web(append: [
            // Before permission / Inertia shared props: Spatie team = current organisation.
            SetPermissionsTeamFromOrganisationMiddleware::class,
            HandleAppearance::class,
            SetLocaleMiddleware::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureTwoFactorIsEnabledMiddleware::class,
            // Last so defense headers apply to the final HTML (app-ui security-headers.md).
            SetSecurityHeadersMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            return RenderHttpErrorResponse::render($exception, $request);
        });
    })->create();
