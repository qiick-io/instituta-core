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
 * The account is soft-deleted and anonymised: email and username become unique
 * placeholders, personal data is cleared, principals are detached and deactivated,
 * and keys owned by those principals are revoked. The user stays only soft-deleted,
 * so activity rows keep their bigint causer and subject ids but lose identifying payload.
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
            $this->anonymiseUser($user);

            $user->delete();
        }, 'user');
    }

    /**
     * Replace personal data with unique placeholders so the original values can be reused.
     */
    private function anonymiseUser(User $user): void
    {
        $publicId = $user->public_id;

        $user->forceFill([
            'first_name' => 'Deleted',
            'last_name' => null,
            'email' => 'deleted+'.$publicId.'@example.invalid',
            'username' => 'deleted_'.substr(str_replace('-', '', $publicId), 0, 24),
            'email_verified_at' => null,
            'last_login_ip' => null,
            'remember_token' => null,
        ])->saveQuietly();
    }

    /**
     * Remove identifying payload from the user's activity without rewriting its ids.
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
            $activity->properties = collect($activity->properties?->toArray() ?? [])
                ->except(self::SENSITIVE_PROPERTY_KEYS);
            $activity->attribute_changes = collect($activity->attribute_changes?->toArray() ?? [])
                ->except(self::SENSITIVE_PROPERTY_KEYS);
            $activity->saveQuietly();
        }
    }
}
