<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ApiKey;
use App\Notifications\Concerns\BroadcastsWithDatabaseTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An API key in the organisation perimeter will expire soon.
 */
class ApiKeyExpiringNotification extends Notification implements ShouldQueue
{
    use BroadcastsWithDatabaseTrait;
    use Queueable;

    public function __construct(
        public readonly ApiKey $apiKey,
        public readonly string $expiresAtIso,
    ) {}

    /**
     * @return array{type: string, organisation_id: string, api_key_id: string, key_name: string, expires_at: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        $this->apiKey->loadMissing('principal');

        return [
            'type' => 'api_key_expiring',
            'organisation_id' => (string) $this->apiKey->principal?->organisation_id,
            'api_key_id' => $this->apiKey->id,
            'key_name' => $this->apiKey->name,
            'expires_at' => $this->expiresAtIso,
            'url' => route('api-keys.index'),
        ];
    }
}
