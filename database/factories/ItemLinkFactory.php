<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemLink>
 */
class ItemLinkFactory extends Factory
{
    protected $model = ItemLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fromItem = Item::factory()->create();

        return [
            'from_item_id' => $fromItem->id,
            'to_item_id' => null,
            'organisation_id' => $fromItem->organisation_id,
            'company_id' => $fromItem->company_id,
            'product_id' => $fromItem->product_id,
            'target' => '[['.fake()->slug().']]',
            'relation' => 'links_to',
        ];
    }

    public function resolved(): static
    {
        return $this->state(function (array $attributes): array {
            $fromItem = Item::query()->whereKey($attributes['from_item_id'])->firstOrFail();

            $toItem = Item::factory()->create([
                'organisation_id' => $fromItem->organisation_id,
                'company_id' => $fromItem->company_id,
                'product_id' => $fromItem->product_id,
                'source_id' => $fromItem->source_id,
            ]);

            return [
                'to_item_id' => $toItem->id,
                'target' => $toItem->stable_key,
            ];
        });
    }
}
