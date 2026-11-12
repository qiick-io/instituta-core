<?php

declare(strict_types=1);

namespace App\Brain\Enums;

enum PrincipalTypeEnum: string
{
    case Person = 'person';
    case Agent = 'agent';
    case Service = 'service';
}
