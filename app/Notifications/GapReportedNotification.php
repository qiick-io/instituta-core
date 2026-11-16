<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\GapReport;
use App\Notifications\Concerns\BroadcastsWithDatabaseTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An agent or person reported a gap in the organisation's brain (#229).
 */
class GapReportedNotification extends Notification implements ShouldQueue
{
    use BroadcastsWithDatabaseTrait;
    use Queueable;

    public function __construct(
        public readonly GapReport $gapReport,
    ) {}

    /**
     * @return array{type: string, organisation_id: string, gap_report_id: string, title: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'gap_reported',
            'organisation_id' => $this->gapReport->organisation_id,
            'gap_report_id' => $this->gapReport->id,
            'title' => $this->gapReport->summary,
            'url' => route('brain.gaps.index'),
        ];
    }
}
