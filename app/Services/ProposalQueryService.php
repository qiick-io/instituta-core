<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Enums\ProposalStatusEnum;
use App\Models\ApiKey;
use App\Models\Proposal;
use Illuminate\Support\Collection;

/**
 * Single proposal query path for API, MCP and the human review UI.
 *
 * API/MCP summaries never include body; UI detail may include body for owners/members.
 */
final class ProposalQueryService
{
    public function __construct(
        private readonly ContractAuditService $audit = new ContractAuditService,
    ) {}

    /**
     * @return Collection<int, Proposal>
     */
    public function modelsInPerimeter(PerimeterData $perimeter, ?ProposalStatusEnum $status = null): Collection
    {
        return Proposal::query()
            ->visibleIn($perimeter)
            ->when(
                $status instanceof ProposalStatusEnum,
                fn ($query) => $query->where('status', $status),
            )
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    /**
     * @return array{proposals: list<array<string, mixed>>}
     */
    public function list(
        PerimeterData $perimeter,
        ApiKey $apiKey,
        ?ProposalStatusEnum $status,
        string $surface,
    ): array {
        $proposals = $this->modelsInPerimeter($perimeter, $status);

        /** @var list<string> $itemIds */
        $itemIds = array_values($proposals->pluck('id')->all());

        $this->audit->record(
            toolOrEndpoint: $surface === 'mcp' ? 'instituta_list_proposals' : 'GET /proposals',
            perimeter: $perimeter,
            apiKey: $apiKey,
            itemIds: $itemIds,
        );

        return [
            'proposals' => array_values(
                $proposals->map(fn (Proposal $proposal): array => $this->toSummary($proposal))->all(),
            ),
        ];
    }

    /**
     * Human UI list (no contract_calls audit; no body).
     *
     * @return list<array<string, mixed>>
     */
    public function listForUi(PerimeterData $perimeter, ?ProposalStatusEnum $status = null): array
    {
        return array_values(
            $this->modelsInPerimeter($perimeter, $status)
                ->map(fn (Proposal $proposal): array => $this->toUiRow($proposal, includeBody: false))
                ->all(),
        );
    }

    /**
     * Perimeter-scoped Eloquent model for status updates.
     */
    public function findInPerimeter(PerimeterData $perimeter, string $proposalId): ?Proposal
    {
        return Proposal::query()
            ->whereKey($proposalId)
            ->visibleIn($perimeter)
            ->first();
    }

    /**
     * Human UI detail (may include body).
     *
     * @return array<string, mixed>|null
     */
    public function findForUi(PerimeterData $perimeter, string $proposalId): ?array
    {
        $proposal = $this->findInPerimeter($perimeter, $proposalId);

        if ($proposal === null) {
            return null;
        }

        return $this->toUiRow($proposal, includeBody: true);
    }

    /**
     * Public contract shape: never includes body.
     *
     * @return array<string, mixed>
     */
    public function toSummary(Proposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'status' => $proposal->status->value,
            'action' => $proposal->action->value,
            'stable_key' => $proposal->stable_key,
            'title' => $proposal->title,
            'path' => $proposal->path,
            'area' => $proposal->area,
            'section_anchor' => $proposal->section_anchor,
            'base_content_hash' => $proposal->base_content_hash,
            'frontmatter_changes' => $proposal->frontmatter_changes,
            'scope_type' => $proposal->scope_type->value,
            'scope_id' => $proposal->scope_id,
            'source_id' => $proposal->source_id,
            'proposed_by_principal_id' => $proposal->proposed_by_principal_id,
            'created_at' => $proposal->created_at?->toIso8601String(),
            'updated_at' => $proposal->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The summary returned right after opening a proposal, plus the other open proposals that
     * change the same item (the whole item, or the same section), so the author and the person
     * deciding know two changes compete for the same content.
     *
     * @return array<string, mixed>
     */
    public function toProposedSummary(Proposal $proposal): array
    {
        return [
            ...$this->toSummary($proposal),
            'overlapping_open_proposals' => $this->overlappingOpenProposalIds($proposal),
        ];
    }

    /**
     * @return list<string>
     */
    public function overlappingOpenProposalIds(Proposal $proposal): array
    {
        /** @var list<string> $ids */
        $ids = Proposal::query()
            ->where('organisation_id', $proposal->organisation_id)
            ->where('stable_key', $proposal->stable_key)
            ->where('status', ProposalStatusEnum::Open)
            ->whereKeyNot($proposal->id)
            ->when($proposal->section_anchor !== null, fn ($query) => $query->where(function ($inner) use ($proposal): void {
                $inner->whereNull('section_anchor')->orWhere('section_anchor', $proposal->section_anchor);
            }))
            ->orderBy('created_at')
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * Inertia UI row: materialisation fields; optional body on detail only.
     *
     * @return array<string, mixed>
     */
    public function toUiRow(Proposal $proposal, bool $includeBody): array
    {
        $row = [
            'id' => $proposal->id,
            'status' => $proposal->status->value,
            'action' => $proposal->action->value,
            'stable_key' => $proposal->stable_key,
            'title' => $proposal->title,
            'path' => $proposal->path,
            'area' => $proposal->area,
            'section_anchor' => $proposal->section_anchor,
            'source_id' => $proposal->source_id,
            'materialisation_status' => $proposal->materialisation_status?->value,
            'remote_branch' => $proposal->remote_branch,
            'remote_pr_url' => $proposal->remote_pr_url,
            'materialised_commit' => $proposal->materialised_commit,
            'materialisation_error' => $proposal->materialisation_error,
            'created_at' => $proposal->created_at?->toIso8601String(),
            'updated_at' => $proposal->updated_at?->toIso8601String(),
        ];

        if ($includeBody) {
            $proposal->loadMissing(['proposedByPrincipal', 'apiKey']);
            $row['body'] = $proposal->body;
            $row['decision_note'] = $proposal->decision_note;
            $row['frontmatter_changes'] = $proposal->frontmatter_changes;
            $row['base_content_hash'] = $proposal->base_content_hash;
            $row['base_commit'] = $proposal->base_commit;
            $row['merged_at'] = $proposal->merged_at?->toIso8601String();
            $row['proposed_by'] = [
                'name' => $proposal->proposedByPrincipal?->name,
                'type' => $proposal->proposedByPrincipal?->type->value,
                'key_name' => $proposal->apiKey?->name,
            ];
            $row['overlapping_open_proposals'] = $this->overlappingOpenProposalIds($proposal);
        }

        return $row;
    }
}
