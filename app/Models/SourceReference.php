<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SourceReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Product pin of a shared source by id + version (case 6, no copy).
 *
 * @property string $id
 * @property string $product_id
 * @property string $source_id
 * @property string|null $source_version_id
 * @property string|null $pinned_commit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['product_id', 'source_id', 'source_version_id', 'pinned_commit'])]
class SourceReference extends Model
{
    /** @use HasFactory<SourceReferenceFactory> */
    use HasFactory, HasUuids;

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
     * @return BelongsTo<SourceVersion, $this>
     */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(SourceVersion::class);
    }
}
