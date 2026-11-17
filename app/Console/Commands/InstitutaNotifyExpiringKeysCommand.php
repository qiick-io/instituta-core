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
 * Notifies organisation users about API keys that expire in the warning window.
 *
 * Runs daily; picks keys that expire between N and N+1 days from now (default N=7)
 * so each key is notified once without extra state. Skips revoked keys and keys
 * without an expiry.
 */
#[AsCommand(name: 'instituta:notify-expiring-keys')]
class InstitutaNotifyExpiringKeysCommand extends Command
{
    protected $signature = 'instituta:notify-expiring-keys
        {--days= : Days ahead to warn (defaults to config api.expiry_warning_days)}';

    protected $description = 'Notify users when organisation API keys are about to expire';

    public function handle(NotifyOrganisationUsersService $notify): int
    {
        $days = (int) ($this->option('days') ?: config('api.expiry_warning_days', 7));
        $days = max(1, $days);
        $windowStart = Carbon::now()->addDays($days);
        $windowEnd = Carbon::now()->addDays($days + 1);

        $keys = ApiKey::query()
            ->with('principal')
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $windowStart)
            ->where('expires_at', '<=', $windowEnd)
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
