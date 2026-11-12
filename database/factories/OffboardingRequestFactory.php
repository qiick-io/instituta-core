<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CompanyKindEnum;
use App\Enums\OffboardingStatusEnum;
use App\Models\Company;
use App\Models\OffboardingRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OffboardingRequest>
 */
class OffboardingRequestFactory extends Factory
{
    protected $model = OffboardingRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory()->state([
                'kind' => CompanyKindEnum::Client,
            ]),
            'status' => OffboardingStatusEnum::Pending,
            'export_path' => null,
            'requested_at' => now(),
            'erased_at' => null,
        ];
    }

    public function exported(): static
    {
        return $this->state(fn (): array => [
            'status' => OffboardingStatusEnum::Exported,
            'export_path' => 'storage/instituta/exports/'.fake()->uuid().'.zip',
        ]);
    }

    public function erased(): static
    {
        return $this->state(fn (): array => [
            'status' => OffboardingStatusEnum::Erased,
            'erased_at' => now(),
        ]);
    }
}
