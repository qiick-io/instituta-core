<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\RoleEnum;
use App\Enums\ProposalStatusEnum;
use App\Models\Proposal;

/**
 * What waits for a person in the session scope (#255): open proposals, and for owners open gap
 * reports. Drives the Review tabs and the sidebar badge; two counts per page load.
 */
final class ReviewCountsService
{
    /**
     * @return array{open_proposals: int, open_gaps: int}
     */
    public function counts(?PerimeterData $perimeter): array
    {
        if ($perimeter === null) {
            return ['open_proposals' => 0, 'open_gaps' => 0];
        }

        return [
            'open_proposals' => Proposal::query()
                ->visibleIn($perimeter)
                ->where('status', ProposalStatusEnum::Open)
                ->count(),
            'open_gaps' => $perimeter->role === RoleEnum::Owner
                ? app(GapReportQueryService::class)->openCount($perimeter)
                : 0,
        ];
    }
}
