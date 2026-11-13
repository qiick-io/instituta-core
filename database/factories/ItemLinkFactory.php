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
        return [
            'from_item_id' => Item::factory(),
            'to_item_id' => null,
            'organisation_id' => fn (array $attributes): mixed => Item::query()->whereKey($attributes['from_item_id'])->value('organisation_id'),
            'company_id' => fn (array $attributes): mixed => Item::query()->whereKey($attributes['from_item_id'])->value('company_id'),
            'product_id' => fn (array $attributes): mixed => Item::query()->whereKey($attributes['from_item_id'])->value('product_id'),
            'target' => '[['.fake()->slug().']]',
            'relation' => 'links_to',
        ];
    }

    /**
     * Resolve the link to a sibling item in the same tenancy (same org / source).
     */
    public function resolved(): static
    {
        return $this->afterCreating(function (ItemLink $itemLink): void {
            $fromItem = Item::query()->findOrFail($itemLink->from_item_id);
            $toItem = Item::factory()->create([
                'organisation_id' => $fromItem->organisation_id,
                'company_id' => $fromItem->company_id,
                'product_id' => $fromItem->product_id,
                'source_id' => $fromItem->source_id,
            ]);

            $itemLink->forceFill([
                'to_item_id' => $toItem->id,
                'target' => $toItem->stable_key,
            ])->save();
        });
    }
}
