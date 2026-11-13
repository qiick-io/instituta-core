<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of turning an accepted proposal into a reviewable git change (#53).
 */
enum ProposalMaterialisationStatusEnum: string
{
    case Pending = 'pending';
    case Materialised = 'materialised';
    case LocalReview = 'local_review';
    case Failed = 'failed';
}
