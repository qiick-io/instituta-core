<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ItemSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Heading-anchored section of an item; holds embedding metadata and JSON vectors.
 *
 * @property string $id
 * @property string $item_id
 * @property string $organisation_id
 * @property string|null $company_id
 * @property string|null $product_id
 * @property string $anchor
 * @property string $heading
 * @property int $level
 * @property int $position
 * @property string $body
 * @property list<array<string, mixed>>|null $blocks
 * @property string|null $embedding_model_id
 * @property Carbon|null $embedded_at
 * @property list<float>|null $embedding_vector
 * @property string|null $embedding_content_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'item_id',
    'organisation_id',
    'company_id',
    'product_id',
    'anchor',
    'heading',
    'level',
    'position',
    'body',
    'blocks',
    'embedding_model_id',
    'embedded_at',
    'embedding_vector',
    'embedding_content_hash',
])]
class ItemSection extends Model
{
    /** @use HasFactory<ItemSectionFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'position' => 'integer',
            'blocks' => 'array',
            'embedded_at' => 'datetime',
            'embedding_vector' => 'array',
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
     * @return BelongsTo<EmbeddingVersion, $this>
     */
    public function embeddingVersion(): BelongsTo
    {
        return $this->belongsTo(EmbeddingVersion::class, 'embedding_model_id');
    }
}
