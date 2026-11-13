<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\ScopeTypeEnum;
use App\Enums\ProposalActionEnum;
use App\Enums\ProposalMaterialisationStatusEnum;
use App\Enums\ProposalStatusEnum;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Scoped brain write proposal. Content lives in `body` for later git PR (#53);
 * never log `body` to contract_calls or activity.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string|null $company_id
 * @property string|null $product_id
 * @property ScopeTypeEnum $scope_type
 * @property string $scope_id
 * @property string|null $source_id
 * @property ProposalStatusEnum $status
 * @property ProposalActionEnum $action
 * @property string $stable_key
 * @property string $title
 * @property string $path
 * @property string|null $area
 * @property string $proposed_by_principal_id
 * @property string|null $api_key_id
 * @property string|null $body
 * @property ProposalMaterialisationStatusEnum|null $materialisation_status
 * @property string|null $remote_branch
 * @property string|null $remote_pr_url
 * @property string|null $materialised_commit
 * @property Carbon|null $materialised_at
 * @property string|null $materialisation_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'organisation_id',
    'company_id',
    'product_id',
    'scope_type',
    'scope_id',
    'source_id',
    'status',
    'action',
    'stable_key',
    'title',
    'path',
    'area',
    'proposed_by_principal_id',
    'api_key_id',
    'body',
    'materialisation_status',
    'remote_branch',
    'remote_pr_url',
    'materialised_commit',
    'materialised_at',
    'materialisation_error',
])]
#[Hidden(['body'])]
class Proposal extends Model
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => ScopeTypeEnum::class,
            'status' => ProposalStatusEnum::class,
            'action' => ProposalActionEnum::class,
            'materialisation_status' => ProposalMaterialisationStatusEnum::class,
            'materialised_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<Principal, $this>
     */
    public function proposedByPrincipal(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'proposed_by_principal_id');
    }

    /**
     * @return BelongsTo<ApiKey, $this>
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ProposalStatusEnum::Open);
    }
}
