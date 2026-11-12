<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceAdapterEnum;
use App\Enums\SourceProviderEnum;
use App\Enums\SyncStatusEnum;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ingest source pinned into a tenancy scope (git, GitHub or local path).
 *
 * @property string $id
 * @property string $organisation_id
 * @property string|null $company_id
 * @property string|null $product_id
 * @property ScopeTypeEnum $scope_type
 * @property string $name
 * @property SourceProviderEnum $provider
 * @property string|null $remote_url
 * @property string|null $local_path
 * @property string|null $ref
 * @property string|null $path
 * @property SourceAdapterEnum $adapter
 * @property VisibilityEnum $default_visibility
 * @property bool $is_shared
 * @property string|null $last_synced_commit
 * @property SyncStatusEnum $sync_status
 * @property Carbon|null $last_synced_at
 * @property array<string, mixed>|null $last_sync_report
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'organisation_id',
    'company_id',
    'product_id',
    'scope_type',
    'name',
    'provider',
    'remote_url',
    'local_path',
    'ref',
    'path',
    'adapter',
    'default_visibility',
    'is_shared',
    'last_synced_commit',
    'sync_status',
    'last_synced_at',
    'last_sync_report',
])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => ScopeTypeEnum::class,
            'provider' => SourceProviderEnum::class,
            'adapter' => SourceAdapterEnum::class,
            'default_visibility' => VisibilityEnum::class,
            'is_shared' => 'boolean',
            'sync_status' => SyncStatusEnum::class,
            'last_synced_at' => 'datetime',
            'last_sync_report' => 'array',
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
     * @return HasMany<SourceVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(SourceVersion::class);
    }

    /**
     * @return HasMany<SourceReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(SourceReference::class);
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeShared(Builder $query): Builder
    {
        return $query->where('is_shared', true);
    }
}
