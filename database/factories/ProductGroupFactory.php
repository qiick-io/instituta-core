<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\ProductGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductGroup>
 */
class ProductGroupFactory extends Factory
{
    protected $model = ProductGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->unique()->word();

        return [
            'organisation_id' => Organisation::factory(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => Str::title($name),
        ];
    }
}
