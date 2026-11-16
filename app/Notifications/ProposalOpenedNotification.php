<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Proposal;
use App\Notifications\Concerns\BroadcastsWithDatabaseTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A write proposal was opened in the organisation perimeter.
 */
class ProposalOpenedNotification extends Notification implements ShouldQueue
{
    use BroadcastsWithDatabaseTrait;
    use Queueable;

    public function __construct(
        public readonly Proposal $proposal,
    ) {}

    /**
     * @return array{type: string, organisation_id: string, proposal_id: string, title: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'proposal_opened',
            'organisation_id' => $this->proposal->organisation_id,
            'proposal_id' => $this->proposal->id,
            'title' => $this->proposal->title,
            'url' => route('review.proposals.show', ['proposal' => $this->proposal->id]),
        ];
    }
}
