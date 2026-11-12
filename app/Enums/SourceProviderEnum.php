<?php

declare(strict_types=1);

namespace App\Enums;

enum SourceProviderEnum: string
{
    case Github = 'github';
    case Git = 'git';
    case Local = 'local';
}
