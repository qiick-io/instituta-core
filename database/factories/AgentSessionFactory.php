<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Brain\Enums\ScopeTypeEnum;
use App\Models\AgentSession;
use App\Models\ApiKey;
use App\Models\VaultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Session bound to an API key. Default vault version is independent; tests that need a
 * matching organisation pass `vault_version_id` (and usually `scope_id`) explicitly.
 *
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
        return [
            'api_key_id' => ApiKey::factory(),
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => fn (array $attributes): mixed => ApiKey::query()->whereKey($attributes['api_key_id'])->value('scope_id'),
            'vault_version_id' => VaultVersion::factory(),
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
