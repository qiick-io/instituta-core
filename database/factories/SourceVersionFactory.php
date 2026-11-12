<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Source;
use App\Models\SourceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SourceVersion>
 */
class SourceVersionFactory extends Factory
{
    protected $model = SourceVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'label' => 'v'.fake()->numerify('#.#.#'),
            'commit' => Str::lower(Str::random(40)),
        ];
    }
}
