<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Quota;
use App\Models\QuotaUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotaUsage>
 */
class QuotaUsageFactory extends Factory
{
    protected $model = QuotaUsage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfMonth();

        return [
            'quota_id' => Quota::factory(),
            'used' => fake()->numberBetween(0, 100),
            'period_start' => $start,
            'period_end' => $start->copy()->endOfMonth(),
        ];
    }
}
