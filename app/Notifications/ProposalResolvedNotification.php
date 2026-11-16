<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ProposalStatusEnum;
use App\Models\Proposal;
use App\Notifications\Concerns\BroadcastsWithDatabaseTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A write proposal was accepted or rejected in the organisation perimeter.
 */
class ProposalResolvedNotification extends Notification implements ShouldQueue
{
    use BroadcastsWithDatabaseTrait;
    use Queueable;

    public function __construct(
        public readonly Proposal $proposal,
        public readonly ProposalStatusEnum $status,
    ) {}

    /**
     * @return array{type: string, organisation_id: string, proposal_id: string, title: string, status: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'proposal_resolved',
            'organisation_id' => $this->proposal->organisation_id,
            'proposal_id' => $this->proposal->id,
            'title' => $this->proposal->title,
            'status' => $this->status->value,
            'url' => route('review.proposals.show', ['proposal' => $this->proposal->id]),
        ];
    }
}
