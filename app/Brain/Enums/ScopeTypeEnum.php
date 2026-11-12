<?php

declare(strict_types=1);

namespace App\Brain\Enums;

enum ScopeTypeEnum: string
{
    case Organisation = 'organisation';
    case Company = 'company';
    case Product = 'product';
}
