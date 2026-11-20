<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Brain\Enums\ScopeTypeEnum;
use App\Enums\QuotaMetricEnum;
use App\Services\QuotaEnforcementService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use ValueError;

#[AsCommand(name: 'instituta:quota:set')]
class InstitutaQuotaSetCommand extends Command
{
    protected $signature = 'instituta:quota:set
        {scope_type : organisation|company}
        {scope_id : Scope UUID}
        {metric : contract_calls|source_syncs}
        {limit : Monthly limit (integer)}';

    protected $description = 'Set a monthly per-tenant quota limit';

    public function handle(QuotaEnforcementService $quotas): int
    {
        try {
            $scopeType = ScopeTypeEnum::from((string) $this->argument('scope_type'));
            $metric = QuotaMetricEnum::from((string) $this->argument('metric'));
        } catch (ValueError) {
            $this->error('Invalid scope_type or metric.');

            return self::FAILURE;
        }

        if ($scopeType === ScopeTypeEnum::Product) {
            $this->error('Product-scoped quotas are not supported yet; use organisation or company.');

            return self::FAILURE;
        }

        $limit = (int) $this->argument('limit');

        if ($limit < 0) {
            $this->error('Limit must be zero or a positive integer.');

            return self::FAILURE;
        }

        $quota = $quotas->setLimit(
            scopeType: $scopeType,
            scopeId: (string) $this->argument('scope_id'),
            metric: $metric,
            limitValue: $limit,
        );

        $this->info('Quota saved.');
        $this->line('id: '.$quota->id);
        $this->line('metric: '.$quota->metric);
        $this->line('limit: '.$quota->limit_value);
        $this->line('period: '.$quota->period);

        return self::SUCCESS;
    }
}
