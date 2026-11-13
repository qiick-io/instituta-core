<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\GrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;

/**
 * ACL grant attaching a principal to a scope with a role and visibility ceiling.
 *
 * @property string $id
 * @property string $principal_id
 * @property ScopeTypeEnum $scope_type
 * @property string $scope_id
 * @property RoleEnum $role
 * @property VisibilityEnum $max_visibility
 * @property string|null $path_prefix
 * @property string|null $area
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'principal_id',
    'scope_type',
    'scope_id',
    'role',
    'max_visibility',
    'path_prefix',
    'area',
    'expires_at',
])]
class Grant extends Model
{
    /** @use HasFactory<GrantFactory> */
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
            'scope_type' => ScopeTypeEnum::class,
            'role' => RoleEnum::class,
            'max_visibility' => VisibilityEnum::class,
            'expires_at' => 'datetime',
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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }
}
