<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\Source;
use App\Models\SourceReference;
use App\Models\SourceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceReference>
 */
class SourceReferenceFactory extends Factory
{
    protected $model = SourceReference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'source_version_id' => SourceVersion::factory()->state([
                'source_id' => Source::factory()->shared(),
            ]),
            'source_id' => function (array $attributes): mixed {
                if ($attributes['source_version_id'] === null) {
                    return Source::factory()->shared();
                }

                return SourceVersion::query()->whereKey($attributes['source_version_id'])->value('source_id');
            },
            'pinned_commit' => null,
        ];
    }

    public function pinnedCommit(string $commit): static
    {
        return $this->state(fn (): array => [
            'source_version_id' => null,
            'pinned_commit' => $commit,
        ]);
    }
}
