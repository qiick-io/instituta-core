<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StructuredRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Queryable structured-table row (never scraped markdown).
 *
 * @property string $id
 * @property string $structured_table_id
 * @property string $item_id
 * @property string $organisation_id
 * @property string|null $company_id
 * @property string|null $product_id
 * @property array<string, mixed> $values
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'structured_table_id',
    'item_id',
    'organisation_id',
    'company_id',
    'product_id',
    'values',
    'position',
])]
class StructuredRow extends Model
{
    /** @use HasFactory<StructuredRowFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'values' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StructuredTable, $this>
     */
    public function structuredTable(): BelongsTo
    {
        return $this->belongsTo(StructuredTable::class);
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
}
