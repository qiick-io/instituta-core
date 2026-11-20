<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\Authorization\SuperAdminGuardService;

/**
 * Erases a dashboard user without destroying the evidence of what they did.
 *
 * A soft delete keeps email and username as unique tombstones. Principals are
 * detached and deactivated, their API keys are revoked rather than cascaded
 * away, and activity rows keep their audit link through the user's public UUID
 * after identifying properties have been removed.
 */
class DeleteUserAction
{
    /** @var list<string> */
    private const SENSITIVE_PROPERTY_KEYS = [
        'email',
        'username',
        'first_name',
        'last_name',
        'name',
        'ip',
        'user_agent',
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    public function __construct(private SuperAdminGuardService $superAdminGuard) {}

    /**
     * Erase the user inside the last-super-admin guard.
     */
    public function execute(User $user): void
    {
        $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($user): void {
            $principalIds = $user->principals()->pluck('id')->all();

            $user->principals()->update([
                'user_id' => null,
                'deactivated_at' => now(),
            ]);

            if ($principalIds !== []) {
                ApiKey::query()
                    ->whereIn('owner_principal_id', $principalIds)
                    ->update(['revoked_at' => now()]);
            }

            $this->anonymiseActivity($user);

            $user->delete();
        }, 'user');
    }

    /**
     * Re-point user activity at the public UUID and remove identifying payload.
     */
    private function anonymiseActivity(User $user): void
    {
        $databaseKey = (string) $user->getKey();

        $activities = Activity::query()
            ->where('causer_type', User::class)
            ->where('causer_id', $databaseKey)
            ->get()
            ->merge(Activity::query()
                ->where('subject_type', User::class)
                ->where('subject_id', $databaseKey)
                ->get())
            ->unique('id');

        foreach ($activities as $activity) {
            if ($activity->causer_type === User::class && (string) $activity->causer_id === $databaseKey) {
                $activity->causer_id = $user->public_id;
            }

            if ($activity->subject_type === User::class && (string) $activity->subject_id === $databaseKey) {
                $activity->subject_id = $user->public_id;
            }

            $activity->properties = collect($activity->properties?->toArray() ?? [])
                ->except(self::SENSITIVE_PROPERTY_KEYS);
            $activity->attribute_changes = collect($activity->attribute_changes?->toArray() ?? [])
                ->except(self::SENSITIVE_PROPERTY_KEYS);
            $activity->saveQuietly();
        }
    }
}
