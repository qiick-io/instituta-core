<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\ScopeTypeEnum;
use Database\Factories\AgentSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Agent session pinned to a vault version; invalidated when the vault moves on.
 *
 * @property string $id
 * @property string $api_key_id
 * @property ScopeTypeEnum $scope_type
 * @property string $scope_id
 * @property string $vault_version_id
 * @property Carbon $started_at
 * @property Carbon|null $invalidated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'api_key_id',
    'scope_type',
    'scope_id',
    'vault_version_id',
    'started_at',
    'invalidated_at',
])]
class AgentSession extends Model
{
    /** @use HasFactory<AgentSessionFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => ScopeTypeEnum::class,
            'started_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApiKey, $this>
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * @return BelongsTo<VaultVersion, $this>
     */
    public function vaultVersion(): BelongsTo
    {
        return $this->belongsTo(VaultVersion::class);
    }

    /**
     * @return HasMany<ContractCall, $this>
     */
    public function contractCalls(): HasMany
    {
        return $this->hasMany(ContractCall::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('invalidated_at');
    }
}
