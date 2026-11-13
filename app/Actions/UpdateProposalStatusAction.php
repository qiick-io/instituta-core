<?php

declare(strict_types=1);

namespace App\Actions;

use App\Brain\Data\PerimeterData;
use App\Data\UpdateProposalStatusData;
use App\Enums\ProposalStatusEnum;
use App\Models\ApiKey;
use App\Models\Proposal;
use App\Services\ActivityEventLoggerService;
use App\Services\ContractAuditService;
use InvalidArgumentException;

/**
 * Accepts or rejects an open proposal inside the caller's perimeter.
 *
 * Git materialisation of accept is #53; this only flips status + audit.
 *
 * @side-effect Updates Proposal status, audits the attempt, logs accepted/rejected (no body).
 */
final class UpdateProposalStatusAction
{
    public function __construct(
        private readonly ContractAuditService $audit = new ContractAuditService,
        private readonly ActivityEventLoggerService $activity = new ActivityEventLoggerService,
    ) {}

    public function execute(
        PerimeterData $perimeter,
        ApiKey $apiKey,
        Proposal $proposal,
        UpdateProposalStatusData $payload,
        string $surface,
    ): Proposal {
        if ($proposal->scope_type !== $perimeter->scopeType || $proposal->scope_id !== $perimeter->scopeId) {
            throw new InvalidArgumentException('Proposal is outside the key perimeter.');
        }

        if ($proposal->status !== ProposalStatusEnum::Open) {
            throw new InvalidArgumentException('Only open proposals can change status.');
        }

        if (! in_array($payload->status, [ProposalStatusEnum::Accepted, ProposalStatusEnum::Rejected], true)) {
            throw new InvalidArgumentException('Status must be accepted or rejected.');
        }

        $proposal->forceFill(['status' => $payload->status])->save();

        $this->audit->record(
            toolOrEndpoint: $surface === 'mcp' ? 'instituta_update_proposal' : 'PATCH /proposals/{id}',
            perimeter: $perimeter,
            apiKey: $apiKey,
            itemIds: [$proposal->id],
        );

        $apiKey->loadMissing('principal.user');
        $properties = [
            'organisation_id' => $proposal->organisation_id,
            'proposal_id' => $proposal->id,
            'status' => $payload->status->value,
        ];

        if ($payload->status === ProposalStatusEnum::Accepted) {
            $this->activity->proposalAccepted($proposal, $apiKey->principal?->user, $properties);
        } else {
            $this->activity->proposalRejected($proposal, $apiKey->principal?->user, $properties);
        }

        return $proposal->refresh();
    }
}
