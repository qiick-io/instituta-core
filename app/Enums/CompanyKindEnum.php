<?php

declare(strict_types=1);

namespace App\Enums;

enum CompanyKindEnum: string
{
    case Own = 'own';
    case Client = 'client';
}
