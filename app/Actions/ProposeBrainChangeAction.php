<?php

declare(strict_types=1);

namespace App\Actions;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\ScopeTypeEnum;
use App\Data\ProposeBrainChangeData;
use App\Enums\ProposalStatusEnum;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Product;
use App\Models\Proposal;
use App\Notifications\ProposalOpenedNotification;
use App\Services\ActivityEventLoggerService;
use App\Services\ContractAuditService;
use App\Services\NotifyOrganisationUsersService;
use InvalidArgumentException;

/**
 * Opens a scoped brain write proposal (never mutates the durable git store).
 *
 * @side-effect Persists a Proposal, audits the attempt, logs proposal.opened (no body).
 */
final class ProposeBrainChangeAction
{
    public function __construct(
        private readonly ContractAuditService $audit = new ContractAuditService,
        private readonly ActivityEventLoggerService $activity = new ActivityEventLoggerService,
    ) {}

    public function execute(
        PerimeterData $perimeter,
        ApiKey $apiKey,
        ProposeBrainChangeData $payload,
        string $surface,
    ): Proposal {
        [$organisationId, $companyId, $productId] = $this->scopeColumns($perimeter);

        if ($organisationId === null || $organisationId === '') {
            throw new InvalidArgumentException('Proposal requires a resolvable organisation scope.');
        }

        $proposal = Proposal::query()->create([
            'organisation_id' => $organisationId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'scope_type' => $perimeter->scopeType,
            'scope_id' => $perimeter->scopeId,
            'source_id' => $payload->sourceId,
            'status' => ProposalStatusEnum::Open,
            'action' => $payload->action,
            'stable_key' => $payload->stableKey,
            'title' => $payload->title,
            'path' => $payload->path,
            'area' => $payload->area,
            'proposed_by_principal_id' => $perimeter->principalId,
            'api_key_id' => $apiKey->id,
            'body' => $payload->body,
        ]);

        $this->audit->record(
            toolOrEndpoint: $surface === 'mcp' ? 'instituta_propose' : 'POST /proposals',
            perimeter: $perimeter,
            apiKey: $apiKey,
            itemIds: [$proposal->id],
        );

        $apiKey->loadMissing('principal.user');
        $this->activity->proposalOpened($proposal, $apiKey->principal?->user, [
            'organisation_id' => $organisationId,
            'proposal_id' => $proposal->id,
            'action' => $payload->action->value,
            'stable_key' => $payload->stableKey,
            'status' => ProposalStatusEnum::Open->value,
        ]);

        app(NotifyOrganisationUsersService::class)->notify(
            $organisationId,
            new ProposalOpenedNotification($proposal),
        );

        return $proposal;
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function scopeColumns(PerimeterData $perimeter): array
    {
        return match ($perimeter->scopeType) {
            ScopeTypeEnum::Organisation => [$perimeter->scopeId, null, null],
            ScopeTypeEnum::Company => [
                Company::query()->whereKey($perimeter->scopeId)->value('organisation_id'),
                $perimeter->scopeId,
                null,
            ],
            ScopeTypeEnum::Product => $this->productScopeColumns($perimeter->scopeId),
        };
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function productScopeColumns(string $productId): array
    {
        $product = Product::query()->with('company')->find($productId);

        return [
            $product?->company?->organisation_id,
            $product?->company_id,
            $productId,
        ];
    }
}
