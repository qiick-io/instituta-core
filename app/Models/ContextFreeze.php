<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ContextFreezeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Emergency freeze pinning a scope to a vault version until lifted.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string|null $company_id
 * @property string|null $product_id
 * @property string $vault_version_id
 * @property string $reason
 * @property string $frozen_by_principal_id
 * @property Carbon $frozen_at
 * @property Carbon|null $lifted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'organisation_id',
    'company_id',
    'product_id',
    'vault_version_id',
    'reason',
    'frozen_by_principal_id',
    'frozen_at',
    'lifted_at',
])]
class ContextFreeze extends Model
{
    /** @use HasFactory<ContextFreezeFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frozen_at' => 'datetime',
            'lifted_at' => 'datetime',
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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<VaultVersion, $this>
     */
    public function vaultVersion(): BelongsTo
    {
        return $this->belongsTo(VaultVersion::class);
    }

    /**
     * @return BelongsTo<Principal, $this>
     */
    public function frozenByPrincipal(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'frozen_by_principal_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lifted_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLifted(Builder $query): Builder
    {
        return $query->whereNotNull('lifted_at');
    }
}
