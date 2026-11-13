<?php

declare(strict_types=1);

namespace App\Actions;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\RoleEnum;
use App\Data\UpdateProposalStatusData;
use App\Enums\ProposalMaterialisationStatusEnum;
use App\Enums\ProposalStatusEnum;
use App\Jobs\MaterialiseProposalJob;
use App\Models\ApiKey;
use App\Models\Proposal;
use App\Models\User;
use App\Services\ActivityEventLoggerService;
use App\Services\ContractAuditService;
use InvalidArgumentException;

/**
 * Accepts or rejects an open proposal inside the caller's perimeter.
 *
 * Accept queues git / GitHub PR materialisation (#53); never writes the index here.
 *
 * @side-effect Updates Proposal status, audits the attempt, logs accepted/rejected (no body), may dispatch MaterialiseProposalJob.
 */
final class UpdateProposalStatusAction
{
    public function __construct(
        private readonly ContractAuditService $audit = new ContractAuditService,
        private readonly ActivityEventLoggerService $activity = new ActivityEventLoggerService,
    ) {}

    public function execute(
        PerimeterData $perimeter,
        ?ApiKey $apiKey,
        Proposal $proposal,
        UpdateProposalStatusData $payload,
        string $surface,
        ?User $actor = null,
    ): Proposal {
        if ($perimeter->role === RoleEnum::Guest) {
            throw new InvalidArgumentException('Guests cannot accept or reject proposals.');
        }

        if ($proposal->scope_type !== $perimeter->scopeType || $proposal->scope_id !== $perimeter->scopeId) {
            throw new InvalidArgumentException('Proposal is outside the key perimeter.');
        }

        if ($proposal->status !== ProposalStatusEnum::Open) {
            throw new InvalidArgumentException('Only open proposals can change status.');
        }

        if (! in_array($payload->status, [ProposalStatusEnum::Accepted, ProposalStatusEnum::Rejected], true)) {
            throw new InvalidArgumentException('Status must be accepted or rejected.');
        }

        $attributes = ['status' => $payload->status];

        if ($payload->status === ProposalStatusEnum::Accepted) {
            $attributes['materialisation_status'] = ProposalMaterialisationStatusEnum::Pending;
            $attributes['materialisation_error'] = null;
        }

        $proposal->forceFill($attributes)->save();

        $this->audit->record(
            toolOrEndpoint: match ($surface) {
                'mcp' => 'instituta_update_proposal',
                'web' => 'PATCH /brain/proposals/{id}',
                default => 'PATCH /proposals/{id}',
            },
            perimeter: $perimeter,
            apiKey: $apiKey,
            itemIds: [$proposal->id],
        );

        $causer = $actor;
        if ($causer === null && $apiKey !== null) {
            $apiKey->loadMissing('principal.user');
            $causer = $apiKey->principal?->user;
        }

        $properties = [
            'organisation_id' => $proposal->organisation_id,
            'proposal_id' => $proposal->id,
            'status' => $payload->status->value,
        ];

        if ($payload->status === ProposalStatusEnum::Accepted) {
            $this->activity->proposalAccepted($proposal, $causer, $properties);
            MaterialiseProposalJob::dispatch($proposal->id);
        } else {
            $this->activity->proposalRejected($proposal, $causer, $properties);
        }

        return $proposal->refresh();
    }
}
