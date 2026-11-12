<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemSection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ItemSection>
 */
class ItemSectionFactory extends Factory
{
    protected $model = ItemSection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $item = Item::factory()->create();
        $heading = fake()->sentence(3);

        return [
            'item_id' => $item->id,
            'organisation_id' => $item->organisation_id,
            'company_id' => $item->company_id,
            'product_id' => $item->product_id,
            'anchor' => Str::slug($heading),
            'heading' => $heading,
            'level' => 2,
            'position' => 1,
            'body' => fake()->paragraphs(2, true),
            'blocks' => [
                ['type' => 'text', 'text' => fake()->paragraph()],
            ],
            'embedding_model_id' => null,
            'embedded_at' => null,
        ];
    }
}
