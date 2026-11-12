<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OffboardingStatusEnum;
use Database\Factories\OffboardingRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Client offboarding export / erasure request (export command in MVP; erasure later).
 *
 * @property string $id
 * @property string $company_id
 * @property OffboardingStatusEnum $status
 * @property string|null $export_path
 * @property Carbon $requested_at
 * @property Carbon|null $erased_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company_id', 'status', 'export_path', 'requested_at', 'erased_at'])]
class OffboardingRequest extends Model
{
    /** @use HasFactory<OffboardingRequestFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OffboardingStatusEnum::class,
            'requested_at' => 'datetime',
            'erased_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', OffboardingStatusEnum::Pending);
    }
}
