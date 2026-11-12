<?php

declare(strict_types=1);

namespace App\Brain\Enums;

enum BlockTypeEnum: string
{
    case Text = 'text';
    case Image = 'image';
    case Table = 'table';
    case DataTable = 'data-table';
    case Code = 'code';
}
