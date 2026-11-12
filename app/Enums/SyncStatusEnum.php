<?php

declare(strict_types=1);

namespace App\Enums;

enum SyncStatusEnum: string
{
    case Idle = 'idle';
    case Syncing = 'syncing';
    case Ok = 'ok';
    case Failed = 'failed';
}
