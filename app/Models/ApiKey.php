<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Bearer API key: plain form is `<prefix>` + 40 random chars, stored as SHA-256 hash.
 *
 * @property string $id
 * @property string $name
 * @property string $prefix
 * @property string $hash
 * @property string $display_prefix
 * @property string $principal_id
 * @property ScopeTypeEnum $scope_type
 * @property string $scope_id
 * @property RoleEnum $role
 * @property VisibilityEnum $max_visibility
 * @property list<string> $abilities
 * @property list<string>|null $ip_allowlist
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'prefix',
    'hash',
    'display_prefix',
    'principal_id',
    'scope_type',
    'scope_id',
    'role',
    'max_visibility',
    'abilities',
    'ip_allowlist',
    'expires_at',
    'last_used_at',
    'revoked_at',
])]
#[Hidden(['hash'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory, HasUlids;

    public const DEFAULT_PREFIX = 'ik_';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => ScopeTypeEnum::class,
            'role' => RoleEnum::class,
            'max_visibility' => VisibilityEnum::class,
            'abilities' => 'array',
            'ip_allowlist' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Principal, $this>
     */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    /**
     * @return HasMany<AgentSession, $this>
     */
    public function agentSessions(): HasMany
    {
        return $this->hasMany(AgentSession::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRevoked(Builder $query): Builder
    {
        return $query->whereNotNull('revoked_at');
    }
}
