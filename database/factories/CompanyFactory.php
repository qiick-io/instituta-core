<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CompanyKindEnum;
use App\Models\Company;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'organisation_id' => Organisation::factory(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'kind' => CompanyKindEnum::Own,
        ];
    }

    public function own(): static
    {
        return $this->state(fn (): array => [
            'kind' => CompanyKindEnum::Own,
        ]);
    }

    public function client(): static
    {
        return $this->state(fn (): array => [
            'kind' => CompanyKindEnum::Client,
        ]);
    }
}
