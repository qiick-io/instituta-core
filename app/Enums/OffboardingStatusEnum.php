<?php

declare(strict_types=1);

namespace App\Enums;

enum OffboardingStatusEnum: string
{
    case Pending = 'pending';
    case Exported = 'exported';
    case Erased = 'erased';
    case Failed = 'failed';
}
