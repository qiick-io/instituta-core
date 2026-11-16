<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a sync did to an item (#228). Wire values: stable.
 */
enum ItemChangeKindEnum: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Removed = 'removed';
}
