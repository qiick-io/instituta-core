<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Proposal;
use App\Services\MaterialiseProposalService;

/**
 * Materialises an accepted proposal into a reviewable git change (branch + PR).
 *
 * Delegates the pipeline to {@see MaterialiseProposalService}; jobs and CLI call this Action.
 */
final class MaterialiseProposalAction
{
    public function __construct(
        private readonly MaterialiseProposalService $materialise,
    ) {}

    public function execute(Proposal $proposal): Proposal
    {
        return $this->materialise->materialise($proposal);
    }
}
