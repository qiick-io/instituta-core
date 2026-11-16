<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a gap report stands (#229): open until an owner dismisses it or turns it into a proposal.
 */
enum GapReportStatusEnum: string
{
    case Open = 'open';
    case Dismissed = 'dismissed';
    case Converted = 'converted';
}
