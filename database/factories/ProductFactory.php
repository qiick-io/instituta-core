<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->unique()->word();

        return [
            'company_id' => Company::factory(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => Str::title($name),
            'inherits_from_parent' => true,
        ];
    }

    public function isolated(): static
    {
        return $this->state(fn (): array => [
            'inherits_from_parent' => false,
        ]);
    }

    public function inheriting(): static
    {
        return $this->state(fn (): array => [
            'inherits_from_parent' => true,
        ]);
    }
}
