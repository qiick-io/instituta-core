<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\PrincipalTypeEnum;
use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\PrincipalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Actor bound to an organisation: person, agent or service.
 *
 * @property string $id
 * @property string $organisation_id
 * @property PrincipalTypeEnum $type
 * @property int|null $user_id
 * @property string|null $owner_principal_id
 * @property string $name
 * @property Carbon|null $expires_at
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'organisation_id',
    'type',
    'user_id',
    'owner_principal_id',
    'name',
    'expires_at',
    'deactivated_at',
])]
class Principal extends Model
{
    /** @use HasFactory<PrincipalFactory> */
    use HasFactory, HasUlids, LogsApplicationActivityTrait;

    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()->useLogName('keys');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PrincipalTypeEnum::class,
            'expires_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Principal, $this>
     */
    public function ownerPrincipal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'owner_principal_id');
    }

    /**
     * @return HasMany<Principal, $this>
     */
    public function ownedPrincipals(): HasMany
    {
        return $this->hasMany(self::class, 'owner_principal_id');
    }

    /**
     * @return HasMany<Grant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class);
    }

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('deactivated_at')
            ->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDeactivated(Builder $query): Builder
    {
        return $query->whereNotNull('deactivated_at');
    }
}
