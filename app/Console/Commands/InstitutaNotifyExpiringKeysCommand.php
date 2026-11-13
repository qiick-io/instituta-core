<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Notifications\ApiKeyExpiringNotification;
use App\Services\NotifyOrganisationUsersService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Notifies organisation users about API keys that expire within the next N days.
 *
 * Runs daily; skips keys already revoked or without an expiry. Uses a
 * notification type + key id fingerprint so the same key is not re-notified
 * every day for the same expiry window (one unread/read row per key until pruned).
 */
#[AsCommand(name: 'instituta:notify-expiring-keys')]
class InstitutaNotifyExpiringKeysCommand extends Command
{
    protected $signature = 'instituta:notify-expiring-keys
        {--days=7 : Notify when expiry is within this many days}';

    protected $description = 'Notify users when organisation API keys are about to expire';

    public function handle(NotifyOrganisationUsersService $notify): int
    {
        $days = max(1, (int) $this->option('days'));
        $horizon = Carbon::now()->addDays($days);

        $keys = ApiKey::query()
            ->with('principal')
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', $horizon)
            ->get();

        $sent = 0;

        foreach ($keys as $key) {
            $organisationId = $key->principal?->organisation_id;

            if ($organisationId === null || $organisationId === '') {
                continue;
            }

            if ($this->alreadyNotified($key)) {
                continue;
            }

            $expiresAt = $key->expires_at;
            if ($expiresAt === null) {
                continue;
            }

            $notify->notify(
                $organisationId,
                new ApiKeyExpiringNotification(
                    apiKey: $key,
                    expiresAtIso: $expiresAt->toIso8601String(),
                ),
            );
            $sent++;
        }

        $this->info("Notified for {$sent} expiring API key(s).");

        return self::SUCCESS;
    }

    /**
     * Skip when any user in the system already has an unread/read row for this key.
     */
    private function alreadyNotified(ApiKey $key): bool
    {
        return DB::table('notifications')
            ->where('type', ApiKeyExpiringNotification::class)
            ->where('data', 'like', '%"api_key_id":"'.$key->id.'"%')
            ->exists();
    }
}
