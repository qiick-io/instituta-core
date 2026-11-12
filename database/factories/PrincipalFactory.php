<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Brain\Enums\PrincipalTypeEnum;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Principal>
 */
class PrincipalFactory extends Factory
{
    protected $model = Principal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'type' => PrincipalTypeEnum::Person,
            'user_id' => User::factory(),
            'owner_principal_id' => null,
            'name' => fake()->name(),
            'expires_at' => null,
            'deactivated_at' => null,
        ];
    }

    public function person(): static
    {
        return $this->state(fn (): array => [
            'type' => PrincipalTypeEnum::Person,
            'user_id' => User::factory(),
            'owner_principal_id' => null,
        ]);
    }

    public function agent(): static
    {
        return $this->state(fn (): array => [
            'type' => PrincipalTypeEnum::Agent,
            'user_id' => null,
            'name' => fake()->userName().'-agent',
            'owner_principal_id' => Principal::factory()->person(),
        ]);
    }

    public function service(): static
    {
        return $this->state(fn (): array => [
            'type' => PrincipalTypeEnum::Service,
            'user_id' => null,
            'name' => fake()->slug(2).'-service',
            'owner_principal_id' => Principal::factory()->person(),
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (): array => [
            'deactivated_at' => now()->subMinute(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
