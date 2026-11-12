<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConnectedSystemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Declared connected system for a product (schema only; never a proxy).
 *
 * @property string $id
 * @property string $product_id
 * @property string $system
 * @property string $base_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['product_id', 'system', 'base_url'])]
class ConnectedSystem extends Model
{
    /** @use HasFactory<ConnectedSystemFactory> */
    use HasFactory, HasUlids;

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
