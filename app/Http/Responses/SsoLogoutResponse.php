<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Http\Responses\LogoutResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's logout response with RP-initiated logout: after an SSO login, logging out of
 * Instituta also ends the session at the identity provider, which then sends the browser back
 * to `/`. A local login, or an IdP without an end-session endpoint, keeps Fortify's response.
 */
final class SsoLogoutResponse implements LogoutResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $endSessionUrl = $request->attributes->get(AppServiceProvider::IDENTITY_PROVIDER_LOGOUT_URL_ATTRIBUTE);

        if (! is_string($endSessionUrl) || $request->wantsJson()) {
            return (new LogoutResponse)->toResponse($request);
        }

        // A full-page visit: the IdP lives on another origin, so an Inertia XHR cannot follow it.
        return Inertia::location($endSessionUrl);
    }
}
