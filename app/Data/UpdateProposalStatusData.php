<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ProposalStatusEnum;

/**
 * Validated status transition for an open proposal (accept / reject).
 */
readonly class UpdateProposalStatusData
{
    public function __construct(
        public ProposalStatusEnum $status,
        public ?string $note = null,
    ) {}
}
