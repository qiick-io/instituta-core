<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\Principal;
use App\Models\User;
use App\Services\Authorization\SuperAdminGuardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Erases a dashboard user without destroying the evidence of what they did.
 *
 * The account is soft-deleted and anonymised: email and username become unique
 * placeholders, personal data is cleared, principals are detached/deactivated and
 * anonymised, keys owned by those principals are revoked and renamed, and related
 * sessions, notifications and password-reset tokens are removed. Activity rows keep
 * their ids but lose identifying payload, including nested attribute changes.
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
        'last_login_ip',
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
            $originalEmail = $user->email;
            $publicId = $user->public_id;
            $databaseKey = (string) $user->getKey();
            /** @var list<string> $principalIds */
            $principalIds = array_values($user->principals()->pluck('id')->all());
            /** @var list<string> $apiKeyIds */
            $apiKeyIds = $principalIds === []
                ? []
                : array_values(ApiKey::query()->whereIn('owner_principal_id', $principalIds)->pluck('id')->all());

            $this->anonymisePrincipals($principalIds, $publicId);
            $this->anonymiseApiKeys($principalIds, $publicId);
            $this->anonymiseActivity($databaseKey, $principalIds, $apiKeyIds);
            $this->deletePersonalRows($user, $originalEmail);
            $this->anonymiseUser($user, $publicId);

            $user->delete();
        }, 'user');
    }

    /**
     * @param  list<string>  $principalIds
     */
    private function anonymisePrincipals(array $principalIds, string $publicId): void
    {
        if ($principalIds === []) {
            return;
        }

        Principal::query()->whereIn('id', $principalIds)->update([
            'user_id' => null,
            'deactivated_at' => now(),
            'name' => 'Deleted principal '.$publicId,
        ]);
    }

    /**
     * @param  list<string>  $principalIds
     */
    private function anonymiseApiKeys(array $principalIds, string $publicId): void
    {
        if ($principalIds === []) {
            return;
        }

        ApiKey::query()
            ->whereIn('owner_principal_id', $principalIds)
            ->update([
                'name' => 'Deleted key '.$publicId,
                'revoked_at' => now(),
            ]);
    }

    /**
     * Remove identifying payload from activity rows about the user, their principals and keys.
     *
     * @param  list<string>  $principalIds
     * @param  list<string>  $apiKeyIds
     */
    private function anonymiseActivity(string $userId, array $principalIds, array $apiKeyIds): void
    {
        $query = Activity::query()
            ->where(function ($builder) use ($userId): void {
                $builder->where(fn ($inner) => $inner->where('causer_type', User::class)->where('causer_id', $userId))
                    ->orWhere(fn ($inner) => $inner->where('subject_type', User::class)->where('subject_id', $userId));
            });

        if ($principalIds !== []) {
            $query->orWhere(fn ($builder) => $builder->where('subject_type', Principal::class)->whereIn('subject_id', $principalIds));
        }

        if ($apiKeyIds !== []) {
            $query->orWhere(fn ($builder) => $builder->where('subject_type', ApiKey::class)->whereIn('subject_id', $apiKeyIds));
        }

        $query->get()->unique('id')->each(function (Activity $activity): void {
            $activity->properties = collect($this->scrubPayload($activity->properties?->toArray() ?? []));
            $activity->attribute_changes = collect($this->scrubPayload($activity->attribute_changes?->toArray() ?? []));
            $activity->saveQuietly();
        });
    }

    private function deletePersonalRows(User $user, string $originalEmail): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $originalEmail)->delete();
        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->delete();
    }

    /**
     * Replace personal data with unique placeholders so the original values can be reused.
     */
    private function anonymiseUser(User $user, string $publicId): void
    {
        $user->forceFill([
            'first_name' => 'Deleted',
            'last_name' => null,
            'email' => 'deleted+'.$publicId.'@example.invalid',
            'username' => 'deleted_'.substr(str_replace('-', '', $publicId), 0, 24),
            'email_verified_at' => null,
            'last_login_ip' => null,
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'password' => Hash::make(Str::random(64)),
        ])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function scrubPayload(array $payload): array
    {
        $scrubbed = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, self::SENSITIVE_PROPERTY_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $scrubbed[$key] = $this->scrubPayload($value);

                continue;
            }

            $scrubbed[$key] = $value;
        }

        return $scrubbed;
    }
}
