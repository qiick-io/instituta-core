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
        $source = Source::factory()->shared()->create();
        $version = SourceVersion::factory()->create([
            'source_id' => $source->id,
        ]);

        return [
            'product_id' => Product::factory(),
            'source_id' => $source->id,
            'source_version_id' => $version->id,
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
