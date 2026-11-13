<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StructuredRow;
use App\Models\StructuredTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StructuredRow>
 */
class StructuredRowFactory extends Factory
{
    protected $model = StructuredRow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'structured_table_id' => StructuredTable::factory(),
            'item_id' => fn (array $attributes): mixed => StructuredTable::query()->whereKey($attributes['structured_table_id'])->value('item_id'),
            'organisation_id' => fn (array $attributes): mixed => StructuredTable::query()->whereKey($attributes['structured_table_id'])->value('organisation_id'),
            'company_id' => fn (array $attributes): mixed => StructuredTable::query()->whereKey($attributes['structured_table_id'])->value('company_id'),
            'product_id' => fn (array $attributes): mixed => StructuredTable::query()->whereKey($attributes['structured_table_id'])->value('product_id'),
            'values' => [
                'name' => fake()->word(),
                'value' => fake()->hexColor(),
            ],
            'position' => 0,
        ];
    }
}
