<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceAdapterEnum;
use App\Enums\SourceProviderEnum;
use App\Enums\SyncStatusEnum;
use App\Models\Organisation;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'company_id' => null,
            'product_id' => null,
            'scope_type' => ScopeTypeEnum::Organisation,
            'name' => fake()->words(2, true),
            'provider' => SourceProviderEnum::Local,
            'remote_url' => null,
            'local_path' => '/tmp/instituta/'.fake()->slug(),
            'ref' => 'main',
            'path' => 'docs',
            'adapter' => SourceAdapterEnum::Instituta,
            'default_visibility' => VisibilityEnum::Internal,
            'is_shared' => false,
            'last_synced_commit' => null,
            'sync_status' => SyncStatusEnum::Idle,
            'last_synced_at' => null,
        ];
    }

    public function shared(): static
    {
        return $this->state(fn (): array => [
            'is_shared' => true,
            'scope_type' => ScopeTypeEnum::Organisation,
            'company_id' => null,
            'product_id' => null,
        ]);
    }

    public function github(): static
    {
        return $this->state(fn (): array => [
            'provider' => SourceProviderEnum::Github,
            'remote_url' => 'https://github.com/'.fake()->userName().'/'.fake()->slug(),
            'local_path' => null,
        ]);
    }

    public function syncing(): static
    {
        return $this->state(fn (): array => [
            'sync_status' => SyncStatusEnum::Syncing,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'sync_status' => SyncStatusEnum::Failed,
        ]);
    }
}
