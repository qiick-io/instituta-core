<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Traits\LogsApplicationActivityTrait;
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
use Spatie\Activitylog\Support\LogOptions;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Bearer API key in the fleet token format `ins_{env}_{id}{secret}`.
 *
 * The plaintext appears once on create/rotate. Storage holds the public
 * `key_prefix` (`ins_{env}_{id}`) and SHA-256 of the full token. Declared
 * divergences from the shared baseline: `principal_id` (keys act as Principals),
 * `scope_type` / `scope_id` (multi-tenancy), `role` / `max_visibility` (graph
 * visibility ceiling), and ulid primary keys.
 *
 * @property string $id
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property string $principal_id
 * @property int|null $created_by
 * @property ScopeTypeEnum $scope_type
 * @property string $scope_id
 * @property RoleEnum $role
 * @property VisibilityEnum $max_visibility
 * @property list<string> $scopes
 * @property list<string>|null $ip_allowlist
 * @property int|null $rate_limit_per_minute
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property Carbon|null $revoked_at
 * @property string|null $previous_key_hash
 * @property Carbon|null $previous_key_valid_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'key_prefix',
    'key_hash',
    'principal_id',
    'created_by',
    'scope_type',
    'scope_id',
    'role',
    'max_visibility',
    'scopes',
    'ip_allowlist',
    'rate_limit_per_minute',
    'expires_at',
    'last_used_at',
    'last_used_ip',
    'revoked_at',
    'previous_key_hash',
    'previous_key_valid_until',
])]
#[Hidden(['key_hash', 'previous_key_hash'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory, HasUlids, LogsApplicationActivityTrait;

    /** Three-letter product prefix in the fleet token format. */
    public const DEFAULT_PREFIX = 'ins';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => ScopeTypeEnum::class,
            'role' => RoleEnum::class,
            'max_visibility' => VisibilityEnum::class,
            'scopes' => 'array',
            'ip_allowlist' => 'array',
            'rate_limit_per_minute' => 'integer',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'previous_key_valid_until' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<AgentSession, $this>
     */
    public function agentSessions(): HasMany
    {
        return $this->hasMany(AgentSession::class);
    }

    /**
     * Whether the key grants a scope (write implies read on the same resource).
     */
    public function hasScope(ApiKeyScopeEnum|string $scope): bool
    {
        return ApiKeyScopeEnum::grants($this->scopes ?? [], $scope);
    }

    /**
     * Null or empty allowlist permits every address.
     */
    public function allowsIp(string $ipAddress): bool
    {
        $allowlist = $this->ip_allowlist;

        if ($allowlist === null || $allowlist === []) {
            return true;
        }

        return IpUtils::checkIp($ipAddress, $allowlist);
    }

    /**
     * Whether the presented full token matches the current or in-grace previous hash.
     */
    public function matchesToken(string $presentedToken): bool
    {
        $presentedHash = hash('sha256', $presentedToken);

        if (hash_equals($this->key_hash, $presentedHash)) {
            return true;
        }

        if (
            $this->previous_key_hash === null
            || $this->previous_key_valid_until === null
            || $this->previous_key_valid_until->isPast()
        ) {
            return false;
        }

        return hash_equals($this->previous_key_hash, $presentedHash);
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

    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()
            ->useLogName('api-keys')
            ->logExcept(['key_hash', 'previous_key_hash']);
    }
}
