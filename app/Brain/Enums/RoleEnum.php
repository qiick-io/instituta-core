<?php

declare(strict_types=1);

namespace App\Brain\Enums;

enum RoleEnum: string
{
    case Owner = 'owner';
    case Member = 'member';
    case Guest = 'guest';
}
