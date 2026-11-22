<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Services\Authorization\PermissionsTeamContextService;
use Closure;

/**
 * Applies Spatie's permissions team id for a queued job that knows its organisation.
 *
 * Jobs that touch authorisation set `$organisationId` (string UUID) on the job class
 * (or implement {@see organisationIdForPermissions()}). Others leave the team cleared.
 */
final class SetPermissionsTeamJobMiddleware
{
    public function __construct(
        private readonly PermissionsTeamContextService $teamContext,
    ) {}

    /**
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $organisationId = null;

        if (method_exists($job, 'organisationIdForPermissions')) {
            $resolved = $job->organisationIdForPermissions();
            $organisationId = is_string($resolved) && $resolved !== '' ? $resolved : null;
        } elseif (isset($job->organisationId) && is_string($job->organisationId) && $job->organisationId !== '') {
            $organisationId = $job->organisationId;
        }

        $this->teamContext->setOrganisation($organisationId);

        return $next($job);
    }
}
