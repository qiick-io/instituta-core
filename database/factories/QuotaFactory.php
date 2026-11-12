<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Brain\Enums\ScopeTypeEnum;
use App\Models\Organisation;
use App\Models\Quota;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quota>
 */
class QuotaFactory extends Factory
{
    protected $model = Quota::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => Organisation::factory(),
            'metric' => 'contract_calls',
            'limit_value' => 10_000,
            'period' => 'month',
        ];
    }
}
