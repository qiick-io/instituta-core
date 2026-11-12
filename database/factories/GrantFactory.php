<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Grant>
 */
class GrantFactory extends Factory
{
    protected $model = Grant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organisation = Organisation::factory()->create();

        return [
            'principal_id' => Principal::factory()->state([
                'organisation_id' => $organisation->id,
            ]),
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => $organisation->id,
            'role' => RoleEnum::Member,
            'max_visibility' => VisibilityEnum::Internal,
            'path_prefix' => null,
            'area' => null,
            'expires_at' => null,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => [
            'role' => RoleEnum::Owner,
            'max_visibility' => VisibilityEnum::Restricted,
        ]);
    }

    public function guest(): static
    {
        return $this->state(fn (): array => [
            'role' => RoleEnum::Guest,
            'max_visibility' => VisibilityEnum::Public,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
