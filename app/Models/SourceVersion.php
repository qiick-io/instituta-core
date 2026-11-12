<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SourceVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Named commit (tag) a product can pin on a source.
 *
 * @property string $id
 * @property string $source_id
 * @property string $label
 * @property string $commit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['source_id', 'label', 'commit'])]
class SourceVersion extends Model
{
    /** @use HasFactory<SourceVersionFactory> */
    use HasFactory, HasUlids;

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return HasMany<SourceReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(SourceReference::class);
    }
}
