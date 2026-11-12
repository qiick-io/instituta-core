<?php

declare(strict_types=1);

namespace App\Enums;

enum ApiKeyAbilityEnum: string
{
    case BrainRead = 'brain:read';
    case BrainWrite = 'brain:write';
}
