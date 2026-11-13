<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Enums\ProposalStatusEnum;
use App\Models\ApiKey;
use App\Models\Proposal;
use Illuminate\Support\Collection;

/**
 * Lists proposals inside a perimeter (metadata only; body is never returned).
 */
final class ProposalQueryService
{
    public function __construct(
        private readonly ContractAuditService $audit = new ContractAuditService,
    ) {}

    /**
     * @return array{proposals: list<array<string, mixed>>}
     */
    public function list(
        PerimeterData $perimeter,
        ApiKey $apiKey,
        ?ProposalStatusEnum $status,
        string $surface,
    ): array {
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
            'scope_type' => $proposal->scope_type->value,
            'scope_id' => $proposal->scope_id,
            'source_id' => $proposal->source_id,
            'proposed_by_principal_id' => $proposal->proposed_by_principal_id,
            'created_at' => $proposal->created_at?->toIso8601String(),
            'updated_at' => $proposal->updated_at?->toIso8601String(),
        ];
    }
}
