<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\QuotaUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Usage counter for one quota period.
 *
 * @property string $id
 * @property string $quota_id
 * @property int $used
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['quota_id', 'used', 'period_start', 'period_end'])]
class QuotaUsage extends Model
{
    /** @use HasFactory<QuotaUsageFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Quota, $this>
     */
    public function quota(): BelongsTo
    {
        return $this->belongsTo(Quota::class);
    }
}
