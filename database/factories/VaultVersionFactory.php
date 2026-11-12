<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\VaultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VaultVersion>
 */
class VaultVersionFactory extends Factory
{
    protected $model = VaultVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'label' => 'instituta@v'.fake()->unique()->numberBetween(1, 9999),
            'source_commits' => [
                'knowledge' => Str::lower(Str::random(40)),
            ],
            'signature' => null,
        ];
    }
}
