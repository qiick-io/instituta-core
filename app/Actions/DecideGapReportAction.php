<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ContractErrorCodeEnum;
use App\Enums\GapReportStatusEnum;
use App\Exceptions\ContractException;
use App\Models\GapReport;
use App\Models\Proposal;
use App\Models\User;
use App\Services\ActivityEventLoggerService;

/**
 * An owner closes a gap report (#229): dismissed, or converted into the proposal that answers it.
 */
final readonly class DecideGapReportAction
{
    public function __construct(
        private ActivityEventLoggerService $activity = new ActivityEventLoggerService,
    ) {}

    /**
     * @throws ContractException When the report is already closed.
     */
    public function execute(GapReport $gapReport, User $owner, ?Proposal $proposal = null): GapReport
    {
        if ($gapReport->status !== GapReportStatusEnum::Open) {
            throw new ContractException(ContractErrorCodeEnum::InvalidChange, 'This gap report is already closed.');
        }

        $status = $proposal === null ? GapReportStatusEnum::Dismissed : GapReportStatusEnum::Converted;

        $gapReport->forceFill([
            'status' => $status,
            'decided_by_user_id' => $owner->id,
            'decided_at' => now(),
            'proposal_id' => $proposal?->id,
        ])->save();

        $this->activity->gapReport($status->value, $gapReport, $owner, [
            'organisation_id' => $gapReport->organisation_id,
            'gap_report_id' => $gapReport->id,
            'proposal_id' => $proposal?->id,
        ]);

        return $gapReport;
    }
}
