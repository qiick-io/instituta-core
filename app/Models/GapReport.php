<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\ScopeTypeEnum;
use App\Enums\GapReportKindEnum;
use App\Enums\GapReportStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A gap, contradiction, unclear or outdated entry an agent (or person) reported (#229). It never
 * changes the brain: an owner dismisses it or turns it into a proposal.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string|null $company_id
 * @property string|null $product_id
 * @property ScopeTypeEnum $scope_type
 * @property string $scope_id
 * @property GapReportKindEnum $kind
 * @property GapReportStatusEnum $status
 * @property string $summary
 * @property string|null $question
 * @property list<array{item: string, stable_key: string, path: string, title: string, anchor: string|null}>|null $related
 * @property string $reported_by_principal_id
 * @property string|null $api_key_id
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $proposal_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Principal|null $reporter
 */
#[Fillable([
    'organisation_id',
    'company_id',
    'product_id',
    'scope_type',
    'scope_id',
    'kind',
    'status',
    'summary',
    'question',
    'related',
    'reported_by_principal_id',
    'api_key_id',
    'decided_by_user_id',
    'decided_at',
    'proposal_id',
])]
class GapReport extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => ScopeTypeEnum::class,
            'kind' => GapReportKindEnum::class,
            'status' => GapReportStatusEnum::class,
            'related' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Principal, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'reported_by_principal_id');
    }
}
