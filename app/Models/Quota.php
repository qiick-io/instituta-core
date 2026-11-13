<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\ScopeTypeEnum;
use Database\Factories\QuotaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Per-tenant cost quota definition (monthly contract_calls / source_syncs).
 *
 * @property string $id
 * @property ScopeTypeEnum $scope_type
 * @property string $scope_id
 * @property string $metric
 * @property int $limit_value
 * @property string $period
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['scope_type', 'scope_id', 'metric', 'limit_value', 'period'])]
class Quota extends Model
{
    /** @use HasFactory<QuotaFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => ScopeTypeEnum::class,
            'limit_value' => 'integer',
        ];
    }

    /**
     * @return HasMany<QuotaUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(QuotaUsage::class);
    }
}
