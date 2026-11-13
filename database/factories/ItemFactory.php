<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Brain\Enums\VisibilityEnum;
use App\Models\Item;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = 'notes/'.fake()->slug().'.md';

        return [
            'source_id' => Source::factory(),
            'organisation_id' => fn (array $attributes): mixed => Source::query()->whereKey($attributes['source_id'])->value('organisation_id'),
            'company_id' => null,
            'product_id' => null,
            'path' => $path,
            'stable_key' => Str::beforeLast($path, '.md'),
            'type' => 'note',
            'area' => 'notes',
            'title' => fake()->sentence(4),
            'summary' => fake()->sentence(),
            'visibility' => VisibilityEnum::Internal,
            'commit' => Str::lower(Str::random(40)),
            'content_hash' => hash('sha256', fake()->paragraph()),
            'frontmatter' => ['title' => fake()->sentence(3)],
            'decision_date' => null,
            'effective_date' => null,
            'review_date' => null,
            'resolution_key' => null,
        ];
    }

    public function public(): static
    {
        return $this->state(fn (): array => [
            'visibility' => VisibilityEnum::Public,
        ]);
    }

    public function restricted(): static
    {
        return $this->state(fn (): array => [
            'visibility' => VisibilityEnum::Restricted,
        ]);
    }

    public function decision(): static
    {
        return $this->state(fn (): array => [
            'type' => 'decision',
            'area' => 'decisions',
            'decision_date' => now()->toDateString(),
            'resolution_key' => 'decision.'.fake()->slug(2),
        ]);
    }
}
