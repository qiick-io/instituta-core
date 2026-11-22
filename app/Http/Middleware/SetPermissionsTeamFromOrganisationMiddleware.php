<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Brain\Data\PerimeterData;
use App\Models\Principal;
use App\Models\User;
use App\Services\Authorization\PermissionsTeamContextService;
use App\Services\SessionPerimeterService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets Spatie's permissions team id from the current organisation before permission checks.
 *
 * Web: session perimeter (organisation / company / product → organisation_id).
 * API: perimeter or principal already attached by AuthenticateApiKeyMiddleware.
 * No organisation leaves the team id cleared so org-scoped roles do not leak.
 */
final class SetPermissionsTeamFromOrganisationMiddleware
{
    public function __construct(
        private readonly PermissionsTeamContextService $teamContext,
        private readonly SessionPerimeterService $sessions,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->teamContext->setOrganisation($this->resolveOrganisationId($request));

        return $next($request);
    }

    private function resolveOrganisationId(Request $request): ?string
    {
        $perimeter = $request->attributes->get('instituta.perimeter');

        if ($perimeter instanceof PerimeterData) {
            return $this->teamContext->organisationIdFromPerimeter($perimeter);
        }

        $principal = $request->attributes->get('instituta.principal');

        if ($principal instanceof Principal && is_string($principal->organisation_id) && $principal->organisation_id !== '') {
            return $principal->organisation_id;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        try {
            $sessionPerimeter = $this->sessions->optionalPerimeter($request);
        } catch (\Throwable) {
            $sessionPerimeter = null;
        }

        if ($sessionPerimeter instanceof PerimeterData) {
            return $this->teamContext->organisationIdFromPerimeter($sessionPerimeter);
        }

        // No session scope yet: use the person principal's organisation (same default as the UI).
        $principal = $this->sessions->personPrincipal($user);

        if ($principal !== null && is_string($principal->organisation_id) && $principal->organisation_id !== '') {
            return $principal->organisation_id;
        }

        return null;
    }
}
