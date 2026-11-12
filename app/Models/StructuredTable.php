<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StructuredTableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Source-of-truth table definition inside an item (tokens, registries, lexicon).
 *
 * @property string $id
 * @property string $item_id
 * @property string $organisation_id
 * @property string|null $company_id
 * @property string|null $product_id
 * @property string $table_key
 * @property list<mixed> $columns
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'item_id',
    'organisation_id',
    'company_id',
    'product_id',
    'table_key',
    'columns',
    'position',
])]
class StructuredTable extends Model
{
    /** @use HasFactory<StructuredTableFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
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
     * @return HasMany<StructuredRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(StructuredRow::class);
    }
}
