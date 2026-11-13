<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a brain write proposal (git PR landings come in #53).
 */
enum ProposalStatusEnum: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
