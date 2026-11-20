<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Product under a company; `inherits_from_parent` false isolates its brain (case 3).
 *
 * @property string $id
 * @property string $company_id
 * @property string $slug
 * @property string|null $logo_path
 * @property string $name
 * @property bool $inherits_from_parent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company_id', 'slug', 'name', 'inherits_from_parent', 'logo_path'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inherits_from_parent' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsToMany<ProductGroup, $this>
     */
    public function productGroups(): BelongsToMany
    {
        return $this->belongsToMany(ProductGroup::class)
            ->withTimestamps();
    }

    /**
     * @return HasMany<SourceReference, $this>
     */
    public function sourceReferences(): HasMany
    {
        return $this->hasMany(SourceReference::class);
    }

    /**
     * @return HasMany<ConnectedSystem, $this>
     */
    public function connectedSystems(): HasMany
    {
        return $this->hasMany(ConnectedSystem::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeIsolated(Builder $query): Builder
    {
        return $query->where('inherits_from_parent', false);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInheriting(Builder $query): Builder
    {
        return $query->where('inherits_from_parent', true);
    }
}
