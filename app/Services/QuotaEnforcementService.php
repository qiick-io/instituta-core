<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Enums\ScopeTypeEnum;
use App\Enums\QuotaMetricEnum;
use App\Exceptions\QuotaExceededException;
use App\Models\Quota;
use App\Models\QuotaUsage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Enforces per-tenant quotas and increments period usage counters.
 */
final class QuotaEnforcementService
{
    /**
     * Consume capacity for a metric under organisation (and optional company) quotas.
     *
     * @throws QuotaExceededException
     */
    public function consume(
        string $organisationId,
        QuotaMetricEnum $metric,
        ?string $companyId = null,
        int $amount = 1,
    ): void {
        if ($amount < 1) {
            return;
        }

        /** @var list<array{0: ScopeTypeEnum, 1: string}> $scopes */
        $scopes = [
            [ScopeTypeEnum::Organisation, $organisationId],
        ];

        if ($companyId !== null && $companyId !== '') {
            $scopes[] = [ScopeTypeEnum::Company, $companyId];
        }

        DB::transaction(function () use ($scopes, $metric, $amount): void {
            foreach ($scopes as [$scopeType, $scopeId]) {
                $quota = Quota::query()
                    ->where('scope_type', $scopeType->value)
                    ->where('scope_id', $scopeId)
                    ->where('metric', $metric->value)
                    ->where('period', 'month')
                    ->lockForUpdate()
                    ->first();

                if (! $quota instanceof Quota) {
                    continue;
                }

                [$periodStart, $periodEnd] = $this->currentMonthWindow();

                $usage = QuotaUsage::query()
                    ->where('quota_id', $quota->id)
                    ->where('period_start', $periodStart)
                    ->lockForUpdate()
                    ->first();

                if (! $usage instanceof QuotaUsage) {
                    $usage = QuotaUsage::query()->create([
                        'quota_id' => $quota->id,
                        'used' => 0,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                    ]);
                    $usage = QuotaUsage::query()
                        ->whereKey($usage->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $nextUsed = $usage->used + $amount;

                if ($nextUsed > $quota->limit_value) {
                    throw new QuotaExceededException(
                        metric: $metric,
                        limitValue: $quota->limit_value,
                        used: $usage->used,
                    );
                }

                $usage->forceFill(['used' => $nextUsed])->save();
            }
        });
    }

    /**
     * Upsert a monthly quota limit for a scope.
     */
    public function setLimit(
        ScopeTypeEnum $scopeType,
        string $scopeId,
        QuotaMetricEnum $metric,
        int $limitValue,
    ): Quota {
        return Quota::query()->updateOrCreate(
            [
                'scope_type' => $scopeType->value,
                'scope_id' => $scopeId,
                'metric' => $metric->value,
                'period' => 'month',
            ],
            [
                'limit_value' => $limitValue,
            ],
        );
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function currentMonthWindow(): array
    {
        $start = now()->startOfMonth()->utc();
        $end = now()->endOfMonth()->utc();

        return [$start, $end];
    }
}
