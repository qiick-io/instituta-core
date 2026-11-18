<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Data\PerimeterData;
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
 * @property array<string, mixed>|null $frontmatter_changes Frontmatter keys to set (null removes) (#219).
 * @property string|null $base_content_hash
 * @property string|null $base_commit
 * @property string|null $section_anchor
 * @property string|null $base_section_hash
 * @property string|null $body
 * @property ProposalMaterialisationStatusEnum|null $materialisation_status
 * @property string|null $remote_branch
 * @property string|null $remote_pr_url
 * @property string|null $materialised_commit
 * @property Carbon|null $materialised_at
 * @property string|null $decision_note Why the person accepted or rejected it (#200).
 * @property Carbon|null $merged_at When a sync first saw the proposed change in the source (#199).
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
    'frontmatter_changes',
    'base_content_hash',
    'base_commit',
    'section_anchor',
    'base_section_hash',
    'materialisation_status',
    'remote_branch',
    'remote_pr_url',
    'materialised_commit',
    'materialised_at',
    'merged_at',
    'decision_note',
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
            'merged_at' => 'datetime',
            'frontmatter_changes' => 'array',
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

    /**
     * Proposals of the perimeter's own scope that stay inside its area and path limits (#334): a
     * proposal's title, path and body describe an item, so one on an item outside the limits must
     * not show. A proposal without an area counts as being in the area of the item it changes.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleIn(Builder $query, PerimeterData $perimeter): Builder
    {
        $query->where($query->qualifyColumn('scope_type'), $perimeter->scopeType)
            ->where($query->qualifyColumn('scope_id'), $perimeter->scopeId);

        if ($perimeter->areas !== []) {
            $query->where(function (Builder $builder) use ($perimeter): void {
                $builder->whereIn($builder->qualifyColumn('area'), $perimeter->areas)
                    ->orWhere(function (Builder $withoutArea) use ($perimeter): void {
                        $withoutArea->whereNull($withoutArea->qualifyColumn('area'))
                            ->whereIn($withoutArea->qualifyColumn('stable_key'), Item::query()->withinPerimeter($perimeter)->select('stable_key'));
                    });
            });
        }

        if ($perimeter->pathPrefixes !== []) {
            Item::wherePathWithin($query, $perimeter->pathPrefixes);
        }

        return $query;
    }
}
