<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an agent found wrong in the brain (#229). Wire values: stable.
 */
enum GapReportKindEnum: string
{
    case Missing = 'missing';
    case Contradiction = 'contradiction';
    case Unclear = 'unclear';
    case Outdated = 'outdated';
}
