<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Proposal;
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
     * Notify the users of the organisation who can read the item a proposal changes (#334): a
     * grant there whose areas and folders reach it. The title travels in the notification, so
     * someone limited to other areas must not get it. A proposal without an area takes the area
     * of the item it changes; when none is known, area-limited users are left out.
     */
    public function notifyAboutProposal(Proposal $proposal, Notification $notification): int
    {
        $area = $proposal->area ?? Item::query()
            ->where('organisation_id', $proposal->organisation_id)
            ->where('stable_key', $proposal->stable_key)
            ->value('area');

        $users = $this->usersForOrganisation($proposal->organisation_id)
            ->filter(static fn (User $user): bool => Grant::query()
                ->active()
                ->whereHas('principal', static function ($query) use ($user, $proposal): void {
                    $query->where('user_id', $user->id)
                        ->where('organisation_id', $proposal->organisation_id)
                        ->whereNull('deactivated_at');
                })
                ->get()
                ->contains(static fn (Grant $grant): bool => PerimeterData::limitsAllow(
                    $grant->areas,
                    $grant->path_prefixes,
                    is_string($area) ? $area : null,
                    $proposal->path,
                )));

        foreach ($users as $user) {
            $user->notify($notification);
        }

        return $users->count();
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
