<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\Principal;
use App\Services\ApiKeyIssuerService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Active API key bound to a fresh principal. The plain secret is never stored: a test that
 * calls the API with the key uses {@see ApiKeyFactory::withSecret()} to choose it.
 *
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
        $secret = $this->makeSecret();

        return [
            'name' => fake()->word().' key',
            'key_prefix' => $secret['key_prefix'],
            'key_hash' => $secret['hash'],
            'principal_id' => Principal::factory(),
            'created_by' => null,
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => fn (array $attributes): mixed => Principal::query()->whereKey($attributes['principal_id'])->value('organisation_id'),
            'role' => RoleEnum::Member,
            'max_visibility' => VisibilityEnum::Internal,
            'scopes' => [ApiKeyScopeEnum::BrainRead->value],
            'ip_allowlist' => null,
            'rate_limit_per_minute' => null,
            'expires_at' => null,
            'last_used_at' => null,
            'last_used_ip' => null,
            'revoked_at' => null,
            'previous_key_hash' => null,
            'previous_key_valid_until' => null,
        ];
    }

    /**
     * @param  array{plain: string, key_prefix: string, hash: string}  $secret
     */
    public function withSecret(array $secret): static
    {
        return $this->state(fn (): array => [
            'key_prefix' => $secret['key_prefix'],
            'key_hash' => $secret['hash'],
        ]);
    }

    public function writeAbility(): static
    {
        return $this->state(fn (): array => [
            'scopes' => [ApiKeyScopeEnum::BrainWrite->value],
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now()->subMinute(),
            'previous_key_hash' => null,
            'previous_key_valid_until' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * @return array{plain: string, key_prefix: string, hash: string}
     */
    public function makeSecret(): array
    {
        return app(ApiKeyIssuerService::class)->makeSecret();
    }
}
