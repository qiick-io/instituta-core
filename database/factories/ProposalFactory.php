<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Brain\Enums\ScopeTypeEnum;
use App\Enums\ProposalActionEnum;
use App\Enums\ProposalStatusEnum;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    protected $model = Proposal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organisation = Organisation::factory()->create();
        $path = 'rules/'.fake()->slug().'.md';

        return [
            'organisation_id' => $organisation->id,
            'company_id' => null,
            'product_id' => null,
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => $organisation->id,
            'source_id' => null,
            'status' => ProposalStatusEnum::Open,
            'action' => ProposalActionEnum::Create,
            'stable_key' => $path,
            'title' => fake()->sentence(3),
            'path' => $path,
            'area' => 'rules',
            'proposed_by_principal_id' => Principal::factory()->state([
                'organisation_id' => $organisation->id,
            ]),
            'api_key_id' => null,
            'body' => "# Draft\n\n".fake()->paragraph(),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'status' => ProposalStatusEnum::Accepted,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ProposalStatusEnum::Rejected,
        ]);
    }
}
