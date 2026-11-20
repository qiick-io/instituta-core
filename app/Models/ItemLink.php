<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ItemLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Graph edge derived from file links / wikilinks at sync time.
 *
 * @property string $id
 * @property string $from_item_id
 * @property string|null $to_item_id
 * @property string $organisation_id
 * @property string|null $company_id
 * @property string|null $product_id
 * @property string $target
 * @property string $relation
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'from_item_id',
    'to_item_id',
    'organisation_id',
    'company_id',
    'product_id',
    'target',
    'relation',
])]
class ItemLink extends Model
{
    /** @use HasFactory<ItemLinkFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Item, $this>
     */
    public function fromItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'from_item_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function toItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'to_item_id');
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
}
