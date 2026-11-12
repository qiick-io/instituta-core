<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContextFreeze;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\VaultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
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
        $organisation = Organisation::factory()->create();
        $principal = Principal::factory()->create([
            'organisation_id' => $organisation->id,
        ]);
        $vaultVersion = VaultVersion::factory()->create([
            'organisation_id' => $organisation->id,
        ]);

        return [
            'organisation_id' => $organisation->id,
            'company_id' => null,
            'product_id' => null,
            'vault_version_id' => $vaultVersion->id,
            'reason' => fake()->sentence(),
            'frozen_by_principal_id' => $principal->id,
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
