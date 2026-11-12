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
        $table = StructuredTable::factory()->create();

        return [
            'structured_table_id' => $table->id,
            'item_id' => $table->item_id,
            'organisation_id' => $table->organisation_id,
            'company_id' => $table->company_id,
            'product_id' => $table->product_id,
            'values' => [
                'name' => fake()->word(),
                'value' => fake()->hexColor(),
            ],
            'position' => 0,
        ];
    }
}
