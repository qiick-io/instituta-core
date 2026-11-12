<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Brain\Enums\ScopeTypeEnum;
use App\Models\AgentSession;
use App\Models\ApiKey;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\VaultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentSession>
 */
class AgentSessionFactory extends Factory
{
    protected $model = AgentSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organisation = Organisation::factory()->create();
        $principal = Principal::factory()->create([
            'organisation_id' => $organisation->id,
        ]);
        $apiKey = ApiKey::factory()->create([
            'principal_id' => $principal->id,
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => $organisation->id,
        ]);
        $vaultVersion = VaultVersion::factory()->create([
            'organisation_id' => $organisation->id,
        ]);

        return [
            'api_key_id' => $apiKey->id,
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => $organisation->id,
            'vault_version_id' => $vaultVersion->id,
            'started_at' => now(),
            'invalidated_at' => null,
        ];
    }

    public function invalidated(): static
    {
        return $this->state(fn (): array => [
            'invalidated_at' => now(),
        ]);
    }
}
