<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContextFreeze;
use App\Models\Principal;
use App\Models\VaultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Freeze row. Default vault version is independent; tests that need a matching
 * organisation pass `vault_version_id` (and usually `organisation_id`) explicitly.
 *
 * @extends Factory<ContextFreeze>
 */
class ContextFreezeFactory extends Factory
{
    protected $model = ContextFreeze::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'frozen_by_principal_id' => Principal::factory(),
            'organisation_id' => fn (array $attributes): mixed => Principal::query()->whereKey($attributes['frozen_by_principal_id'])->value('organisation_id'),
            'company_id' => null,
            'product_id' => null,
            'vault_version_id' => VaultVersion::factory(),
            'reason' => fake()->sentence(),
            'frozen_at' => now(),
            'lifted_at' => null,
        ];
    }

    public function lifted(): static
    {
        return $this->state(fn (): array => [
            'lifted_at' => now(),
        ]);
    }
}
