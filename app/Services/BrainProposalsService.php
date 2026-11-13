<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Enums\ProposalStatusEnum;
use App\Models\Proposal;
use Illuminate\Support\Collection;

/**
 * Proposal list and detail for the human review UI (#55).
 * Detail may include body for owners/members; never put body in activity or contract_calls.
 */
final class BrainProposalsService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(PerimeterData $perimeter, ?ProposalStatusEnum $status = null): array
    {
        /** @var Collection<int, Proposal> $proposals */
        $proposals = Proposal::query()
            ->where('scope_type', $perimeter->scopeType)
            ->where('scope_id', $perimeter->scopeId)
            ->when(
                $status instanceof ProposalStatusEnum,
                fn ($query) => $query->where('status', $status),
            )
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return array_values(
            $proposals->map(fn (Proposal $proposal): array => $this->toRow($proposal, includeBody: false))->all(),
        );
    }

    /**
     * Perimeter-scoped Eloquent model for status updates from the human UI.
     */
    public function findModel(PerimeterData $perimeter, string $proposalId): ?Proposal
    {
        return Proposal::query()
            ->whereKey($proposalId)
            ->where('scope_type', $perimeter->scopeType)
            ->where('scope_id', $perimeter->scopeId)
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(PerimeterData $perimeter, string $proposalId): ?array
    {
        $proposal = $this->findModel($perimeter, $proposalId);

        if ($proposal === null) {
            return null;
        }

        return $this->toRow($proposal, includeBody: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Proposal $proposal, bool $includeBody): array
    {
        $row = [
            'id' => $proposal->id,
            'status' => $proposal->status->value,
            'action' => $proposal->action->value,
            'stable_key' => $proposal->stable_key,
            'title' => $proposal->title,
            'path' => $proposal->path,
            'area' => $proposal->area,
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
            $row['body'] = $proposal->body;
        }

        return $row;
    }
}
