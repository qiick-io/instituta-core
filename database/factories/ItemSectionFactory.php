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
        $heading = fake()->sentence(3);

        return [
            'item_id' => Item::factory(),
            'organisation_id' => fn (array $attributes): mixed => Item::query()->whereKey($attributes['item_id'])->value('organisation_id'),
            'company_id' => fn (array $attributes): mixed => Item::query()->whereKey($attributes['item_id'])->value('company_id'),
            'product_id' => fn (array $attributes): mixed => Item::query()->whereKey($attributes['item_id'])->value('product_id'),
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
