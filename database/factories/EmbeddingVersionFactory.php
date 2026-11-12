<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmbeddingVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmbeddingVersion>
 */
class EmbeddingVersionFactory extends Factory
{
    protected $model = EmbeddingVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'text-embedding-'.fake()->numerify('###'),
            'version' => 'v'.fake()->numerify('#.#'),
            'dimensions' => 1536,
        ];
    }
}
