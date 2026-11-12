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
        $item = Item::factory()->create();

        return [
            'item_id' => $item->id,
            'organisation_id' => $item->organisation_id,
            'company_id' => $item->company_id,
            'product_id' => $item->product_id,
            'table_key' => fake()->slug(2),
            'columns' => ['name', 'value'],
            'position' => 0,
        ];
    }
}
