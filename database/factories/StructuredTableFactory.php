<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\StructuredTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StructuredTable>
 */
class StructuredTableFactory extends Factory
{
    protected $model = StructuredTable::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'organisation_id' => fn (array $attributes): mixed => Item::query()->whereKey($attributes['item_id'])->value('organisation_id'),
            'company_id' => fn (array $attributes): mixed => Item::query()->whereKey($attributes['item_id'])->value('company_id'),
            'product_id' => fn (array $attributes): mixed => Item::query()->whereKey($attributes['item_id'])->value('product_id'),
            'table_key' => fake()->slug(2),
            'columns' => ['name', 'value'],
            'position' => 0,
        ];
    }
}
