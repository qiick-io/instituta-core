<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Brain\Enums\ScopeTypeEnum;
use App\Models\ContractCall;
use App\Models\Principal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractCall>
 */
class ContractCallFactory extends Factory
{
    protected $model = ContractCall::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'principal_id' => Principal::factory(),
            'api_key_id' => null,
            'agent_session_id' => null,
            'tool_or_endpoint' => 'brain.search',
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => fn (array $attributes): mixed => Principal::query()->whereKey($attributes['principal_id'])->value('organisation_id'),
            'organisation_id' => fn (array $attributes): mixed => Principal::query()->whereKey($attributes['principal_id'])->value('organisation_id'),
            'company_id' => null,
            'product_id' => null,
            'item_ids' => [],
            'token_estimate' => fake()->numberBetween(10, 500),
            'called_at' => now(),
        ];
    }
}
