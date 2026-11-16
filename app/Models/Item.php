<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\ItemStatusEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Concerns\AppliesPerimeterScopeTrait;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Indexed brain document carrying denormalised scope columns for isolation.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string|null $company_id
 * @property string|null $product_id
 * @property string $source_id
 * @property string $path
 * @property string $stable_key
 * @property string $type
 * @property string $area
 * @property string $title
 * @property string|null $summary
 * @property VisibilityEnum $visibility
 * @property string|null $commit
 * @property string $content_hash
 * @property int $token_estimate
 * @property array<string, mixed>|null $frontmatter
 * @property ItemStatusEnum $status
 * @property string|null $superseded_by
 * @property Carbon|null $decision_date
 * @property Carbon|null $effective_date
 * @property Carbon|null $review_date
 * @property string|null $resolution_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'organisation_id',
    'company_id',
    'product_id',
    'source_id',
    'path',
    'stable_key',
    'type',
    'area',
    'title',
    'summary',
    'visibility',
    'commit',
    'content_hash',
    'token_estimate',
    'frontmatter',
    'status',
    'superseded_by',
    'decision_date',
    'effective_date',
    'review_date',
    'resolution_key',
])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use AppliesPerimeterScopeTrait, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => VisibilityEnum::class,
            'token_estimate' => 'integer',
            'frontmatter' => 'array',
            'status' => ItemStatusEnum::class,
            'decision_date' => 'date',
            'effective_date' => 'date',
            'review_date' => 'date',
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
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return HasMany<ItemSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(ItemSection::class);
    }

    /**
     * @return HasMany<StructuredTable, $this>
     */
    public function structuredTables(): HasMany
    {
        return $this->hasMany(StructuredTable::class);
    }

    /**
     * @return HasMany<ItemLink, $this>
     */
    public function outboundLinks(): HasMany
    {
        return $this->hasMany(ItemLink::class, 'from_item_id');
    }

    /**
     * @return HasMany<ItemLink, $this>
     */
    public function inboundLinks(): HasMany
    {
        return $this->hasMany(ItemLink::class, 'to_item_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibility(Builder $query, VisibilityEnum|string $visibility): Builder
    {
        $value = $visibility instanceof VisibilityEnum ? $visibility->value : $visibility;

        return $query->where('visibility', $value);
    }
}
