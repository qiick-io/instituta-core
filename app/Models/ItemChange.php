<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\ItemStatusEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ItemChangeKindEnum;
use App\Models\Concerns\AppliesPerimeterScopeTrait;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One change a sync made to one item (#228): created, updated (with the sections whose content
 * changed) or removed. Rows outlive removed items, so agents learn about deletions too.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string|null $company_id
 * @property string|null $product_id
 * @property string $source_id
 * @property string $item_id
 * @property ItemChangeKindEnum $kind
 * @property string $stable_key
 * @property string $path
 * @property string $title
 * @property string $area
 * @property VisibilityEnum $visibility
 * @property ItemStatusEnum $status
 * @property string|null $superseded_by
 * @property string|null $commit
 * @property list<string>|null $changed_sections
 * @property Carbon $changed_at
 */
#[Fillable([
    'organisation_id',
    'company_id',
    'product_id',
    'source_id',
    'item_id',
    'kind',
    'stable_key',
    'path',
    'title',
    'area',
    'visibility',
    'status',
    'superseded_by',
    'commit',
    'changed_sections',
    'changed_at',
])]
class ItemChange extends Model
{
    use AppliesPerimeterScopeTrait, HasUlids;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ItemChangeKindEnum::class,
            'visibility' => VisibilityEnum::class,
            'status' => ItemStatusEnum::class,
            'changed_sections' => 'array',
            'changed_at' => 'datetime',
        ];
    }
}
