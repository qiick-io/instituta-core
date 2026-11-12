<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Brain\Enums\ScopeTypeEnum;
use App\Models\ContractCall;
use App\Models\Organisation;
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
        $organisation = Organisation::factory()->create();
        $principal = Principal::factory()->create([
            'organisation_id' => $organisation->id,
        ]);

        return [
            'principal_id' => $principal->id,
            'api_key_id' => null,
            'agent_session_id' => null,
            'tool_or_endpoint' => 'brain.search',
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => $organisation->id,
            'organisation_id' => $organisation->id,
            'company_id' => null,
            'product_id' => null,
            'item_ids' => [],
            'token_estimate' => fake()->numberBetween(10, 500),
            'called_at' => now(),
        ];
    }
}
