<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\ItemStatusEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Concerns\AppliesPerimeterScopeTrait;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
    use AppliesPerimeterScopeTrait, HasFactory, HasUuids;

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
     * The items a reference names (an id, a stable key or a path), best match first, so `first()`
     * is the item the reference means (#328).
     *
     * Several items in one perimeter can share a stable key or path: the organisation's rule and the
     * client or product overrides of it (`rules/coding`). The exact id wins; then the item of the
     * widest scope (organisation, then company, then product), so an organisation key reads the
     * organisation's own rule and a company key the company's own rule, never one product's
     * override. The path and id make the order total, so the same call always reads the same item.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeMatchingReference(Builder $query, string $reference): Builder
    {
        // PostgreSQL (and MySQL uuid columns) reject non-UUID binds on id; only compare id when the
        // reference is a UUID, otherwise match stable_key / path alone.
        $isUuid = Str::isUuid($reference);

        return $query
            ->where(function (Builder $builder) use ($reference, $isUuid): void {
                if ($isUuid) {
                    $builder->whereKey($reference)
                        ->orWhere($builder->qualifyColumn('stable_key'), $reference)
                        ->orWhere($builder->qualifyColumn('path'), $reference);

                    return;
                }

                $builder->where($builder->qualifyColumn('stable_key'), $reference)
                    ->orWhere($builder->qualifyColumn('path'), $reference);
            })
            ->when(
                $isUuid,
                fn (Builder $ordered): Builder => $ordered->orderByRaw('CASE WHEN items.id = ? THEN 0 ELSE 1 END', [$reference]),
            )
            ->widestScopeFirst();
    }

    /**
     * Orders items from the widest scope to the narrowest (organisation, company, product), then by
     * path and id, so a lookup by a key several scopes share is deterministic (#328).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWidestScopeFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN items.product_id IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN items.company_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy($query->qualifyColumn('path'))
            ->orderBy($query->qualifyColumn('id'));
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
