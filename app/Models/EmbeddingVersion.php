<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmbeddingVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Registry row for an embedding model version used by BrainEmbeddingService.
 *
 * @property string $id
 * @property string $name
 * @property string $version
 * @property int $dimensions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('embedding_models')]
#[Fillable(['name', 'version', 'dimensions'])]
class EmbeddingVersion extends Model
{
    /** @use HasFactory<EmbeddingVersionFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
        ];
    }

    /**
     * @return HasMany<ItemSection, $this>
     */
    public function itemSections(): HasMany
    {
        return $this->hasMany(ItemSection::class, 'embedding_model_id');
    }
}
