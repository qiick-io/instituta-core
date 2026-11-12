<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyAbilityEnum;
use App\Models\ApiKey;
use App\Models\Organisation;
use App\Models\Principal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organisation = Organisation::factory()->create();
        $secret = $this->makeSecret();

        return [
            'name' => fake()->word().' key',
            'prefix' => ApiKey::DEFAULT_PREFIX,
            'hash' => $secret['hash'],
            'display_prefix' => $secret['display_prefix'],
            'principal_id' => Principal::factory()->state([
                'organisation_id' => $organisation->id,
            ]),
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => $organisation->id,
            'role' => RoleEnum::Member,
            'max_visibility' => VisibilityEnum::Internal,
            'abilities' => [ApiKeyAbilityEnum::BrainRead->value],
            'ip_allowlist' => null,
            'expires_at' => null,
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }

    /**
     * @param  array{plain: string, display_prefix: string, hash: string}  $secret
     */
    public function withSecret(array $secret): static
    {
        return $this->state(fn (): array => [
            'hash' => $secret['hash'],
            'display_prefix' => $secret['display_prefix'],
        ]);
    }

    public function writeAbility(): static
    {
        return $this->state(fn (): array => [
            'abilities' => [
                ApiKeyAbilityEnum::BrainRead->value,
                ApiKeyAbilityEnum::BrainWrite->value,
            ],
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now()->subMinute(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * @return array{plain: string, display_prefix: string, hash: string}
     */
    public function makeSecret(string $prefix = ApiKey::DEFAULT_PREFIX): array
    {
        $plain = $prefix.Str::random(40);
        $displayPrefix = substr($plain, 0, 12);

        return [
            'plain' => $plain,
            'display_prefix' => $displayPrefix,
            'hash' => hash('sha256', $plain),
        ];
    }
}
