<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Source;
use App\Notifications\Concerns\BroadcastsWithDatabaseTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Source sync failed inside the caller's organisation perimeter.
 */
class SyncFailedNotification extends Notification implements ShouldQueue
{
    use BroadcastsWithDatabaseTrait;
    use Queueable;

    public function __construct(
        public readonly Source $source,
        public readonly string $reason,
    ) {}

    /**
     * @return array{type: string, organisation_id: string, source_id: string, source_name: string, reason: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sync_failed',
            'organisation_id' => $this->source->organisation_id,
            'source_id' => $this->source->id,
            'source_name' => $this->source->name,
            'reason' => $this->reason,
            'url' => route('repositories.index'),
        ];
    }
}
