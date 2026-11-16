<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Source;
use App\Notifications\Concerns\BroadcastsWithDatabaseTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Source sync completed successfully inside the caller's organisation perimeter.
 */
class SyncFinishedNotification extends Notification implements ShouldQueue
{
    use BroadcastsWithDatabaseTrait;
    use Queueable;

    public function __construct(
        public readonly Source $source,
        public readonly int $itemsUpserted,
    ) {}

    /**
     * @return array{type: string, organisation_id: string, source_id: string, source_name: string, items_upserted: int, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sync_finished',
            'organisation_id' => $this->source->organisation_id,
            'source_id' => $this->source->id,
            'source_name' => $this->source->name,
            'items_upserted' => $this->itemsUpserted,
            'url' => route('repositories.index'),
        ];
    }
}
