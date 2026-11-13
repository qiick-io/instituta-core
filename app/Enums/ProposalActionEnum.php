<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kind of change a brain write proposal requests.
 */
enum ProposalActionEnum: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
