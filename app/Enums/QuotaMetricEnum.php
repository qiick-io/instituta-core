<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Metrics that per-tenant quotas can limit.
 */
enum QuotaMetricEnum: string
{
    case ContractCalls = 'contract_calls';
    case SourceSyncs = 'source_syncs';
}
