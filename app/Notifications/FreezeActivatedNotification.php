<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ContextFreeze;
use App\Notifications\Concerns\BroadcastsWithDatabaseTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An emergency context freeze was raised in the organisation perimeter.
 */
class FreezeActivatedNotification extends Notification implements ShouldQueue
{
    use BroadcastsWithDatabaseTrait;
    use Queueable;

    public function __construct(
        public readonly ContextFreeze $freeze,
    ) {}

    /**
     * @return array{type: string, organisation_id: string, freeze_id: string, reason: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'freeze_activated',
            'organisation_id' => $this->freeze->organisation_id,
            'freeze_id' => $this->freeze->id,
            'reason' => $this->freeze->reason,
            'url' => route('activity.index'),
        ];
    }
}
