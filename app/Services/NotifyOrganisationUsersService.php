<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Fans out a database notification to active users with a principal in an organisation.
 *
 * Perimeter scoping: only users bound to that organisation receive the event; never
 * cross-tenant. Company / product ids travel in the notification payload for links.
 */
final class NotifyOrganisationUsersService
{
    /**
     * @return Collection<int, User>
     */
    public function usersForOrganisation(string $organisationId): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas(
                'principals',
                static function ($query) use ($organisationId): void {
                    $query->where('organisation_id', $organisationId)
                        ->whereNull('deactivated_at');
                },
            )
            ->get();
    }

    /**
     * Notify every active user with a principal in the organisation.
     */
    public function notify(string $organisationId, Notification $notification): int
    {
        $users = $this->usersForOrganisation($organisationId);

        foreach ($users as $user) {
            $user->notify($notification);
        }

        return $users->count();
    }
}
