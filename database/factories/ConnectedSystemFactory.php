<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConnectedSystem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectedSystem>
 */
class ConnectedSystemFactory extends Factory
{
    protected $model = ConnectedSystem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'system' => fake()->slug(2),
            'base_url' => fake()->url(),
        ];
    }
}
