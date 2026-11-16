<?php

declare(strict_types=1);

namespace App\Actions;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\RoleEnum;
use App\Data\ProposeBrainChangeData;
use App\Enums\ContractErrorCodeEnum;
use App\Enums\ProposalActionEnum;
use App\Enums\ProposalStatusEnum;
use App\Exceptions\ContractException;
use App\Models\ApiKey;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\ProposalOpenedNotification;
use App\Services\ActivityEventLoggerService;
use App\Services\ContractAuditService;
use App\Services\FrontmatterEditorService;
use App\Services\NotifyOrganisationUsersService;
use App\Services\PerimeterScopeColumnsService;
use InvalidArgumentException;

/**
 * Opens a scoped brain write proposal (never mutates the durable git store).
 *
 * Updates and deletes name the version their author read (#198): the item's `content_hash`,
 * or for a one-section edit that section's hash. A stale base is refused here, so the author
 * re-reads before proposing; materialising checks the file again before writing.
 *
 * @side-effect Persists a Proposal, audits the attempt, logs proposal.opened (no body).
 */
final class ProposeBrainChangeAction
{
    public function __construct(
        private readonly ContractAuditService $audit = new ContractAuditService,
        private readonly ActivityEventLoggerService $activity = new ActivityEventLoggerService,
        private readonly PerimeterScopeColumnsService $scopeColumns = new PerimeterScopeColumnsService,
        private readonly FrontmatterEditorService $frontmatter = new FrontmatterEditorService,
    ) {}

    /**
     * @param  ApiKey|null  $apiKey  Null when a signed-in person proposes from the web UI.
     * @param  User|null  $actor  The signed-in person on the web; for keys the key's user.
     */
    public function execute(
        PerimeterData $perimeter,
        ?ApiKey $apiKey,
        ProposeBrainChangeData $payload,
        string $surface,
        ?User $actor = null,
    ): Proposal {
        if ($perimeter->role === RoleEnum::Guest) {
            throw new ContractException(ContractErrorCodeEnum::GuestRefused, 'Guests cannot propose changes.');
        }

        [$organisationId, $companyId, $productId] = $this->scopeColumns->columnsFor($perimeter);

        if ($organisationId === null || $organisationId === '') {
            throw new ContractException(ContractErrorCodeEnum::OutOfPerimeter, 'Proposal requires a resolvable organisation scope.');
        }

        $this->assertFrontmatterChanges($payload);
        $base = $this->resolveBase($perimeter, $payload);
        $this->assertHasContent($payload);

        $proposal = Proposal::query()->create([
            'organisation_id' => $organisationId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'scope_type' => $perimeter->scopeType,
            'scope_id' => $perimeter->scopeId,
            'source_id' => $payload->sourceId ?? $base['source_id'],
            'status' => ProposalStatusEnum::Open,
            'action' => $payload->action,
            'stable_key' => $payload->stableKey,
            'title' => $payload->title,
            'path' => $payload->path,
            'area' => $payload->area,
            'proposed_by_principal_id' => $perimeter->principalId,
            'api_key_id' => $apiKey?->id,
            'body' => $payload->body,
            'frontmatter_changes' => $payload->frontmatterChanges,
            'base_content_hash' => $base['base_content_hash'],
            'base_commit' => $base['base_commit'],
            'section_anchor' => $payload->sectionAnchor,
            'base_section_hash' => $base['base_section_hash'],
        ]);

        $this->audit->record(
            toolOrEndpoint: match ($surface) {
                'mcp' => 'instituta_propose',
                'web' => 'POST /review/proposals',
                default => 'POST /proposals',
            },
            perimeter: $perimeter,
            apiKey: $apiKey,
            itemIds: [$proposal->id],
        );

        $apiKey?->loadMissing('principal.user');
        $this->activity->proposalOpened($proposal, $actor ?? $apiKey?->principal?->user, [
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
     * Frontmatter changes (#219) are allowed on update only, with values that fit the format.
     *
     * @throws InvalidArgumentException When they are not.
     */
    private function assertFrontmatterChanges(ProposeBrainChangeData $payload): void
    {
        if ($payload->frontmatterChanges === null) {
            return;
        }

        if ($payload->action !== ProposalActionEnum::Update) {
            throw new ContractException(ContractErrorCodeEnum::InvalidChange, 'frontmatter is only allowed on update.');
        }

        $this->frontmatter->assertValid($payload->frontmatterChanges);
    }

    /**
     * An update carries a body (whole file or one section), frontmatter changes, or both.
     *
     * @throws InvalidArgumentException When it carries nothing to change.
     */
    private function assertHasContent(ProposeBrainChangeData $payload): void
    {
        $hasBody = $payload->body !== null && $payload->body !== '';

        if ($payload->sectionAnchor !== null && ! $hasBody) {
            throw new ContractException(ContractErrorCodeEnum::InvalidChange, 'A section edit needs the section\'s new markdown as body.');
        }

        if ($payload->action === ProposalActionEnum::Update && ! $hasBody && $payload->frontmatterChanges === null) {
            throw new ContractException(ContractErrorCodeEnum::InvalidChange, 'An update needs a body, frontmatter changes, or both.');
        }
    }

    /**
     * The version the proposal is based on, checked against the index.
     *
     * @return array{base_content_hash: string|null, base_commit: string|null, base_section_hash: string|null, source_id: string|null}
     *
     * @throws InvalidArgumentException When the item or section is missing, the base is missing or stale, or the request mixes create / delete with a section.
     */
    private function resolveBase(PerimeterData $perimeter, ProposeBrainChangeData $payload): array
    {
        $existingItem = Item::query()
            ->withinPerimeter($perimeter)
            ->where('stable_key', $payload->stableKey)
            ->first();

        if ($payload->action === ProposalActionEnum::Create) {
            if ($payload->sectionAnchor !== null) {
                throw new ContractException(ContractErrorCodeEnum::InvalidChange, 'section_anchor is only allowed on update.');
            }

            if ($existingItem instanceof Item) {
                throw new ContractException(ContractErrorCodeEnum::ItemExists, 'An item with this stable_key already exists; propose an update instead.');
            }

            return ['base_content_hash' => null, 'base_commit' => null, 'base_section_hash' => null, 'source_id' => null];
        }

        if (! $existingItem instanceof Item) {
            throw new ContractException(ContractErrorCodeEnum::NotFound, 'No item with this stable_key in your perimeter; re-read the brain.');
        }

        if ($payload->path !== $existingItem->path) {
            throw new ContractException(ContractErrorCodeEnum::PathMismatch, 'path must be the item\'s current path ('.$existingItem->path.').');
        }

        $base = [
            'base_content_hash' => $existingItem->content_hash,
            'base_commit' => $existingItem->commit,
            'base_section_hash' => null,
            'source_id' => $existingItem->source_id,
        ];

        if ($payload->sectionAnchor === null) {
            $this->assertCurrent($payload->baseContentHash, $existingItem->content_hash, 'base_content_hash');

            return $base;
        }

        if ($payload->action === ProposalActionEnum::Delete) {
            throw new ContractException(ContractErrorCodeEnum::InvalidChange, 'section_anchor is only allowed on update.');
        }

        /** @var ItemSection|null $section */
        $section = $existingItem->sections()->where('anchor', $payload->sectionAnchor)->first();

        if (! $section instanceof ItemSection) {
            throw new ContractException(ContractErrorCodeEnum::SectionMissing, 'No section with this anchor in the item; re-read it.');
        }

        $currentSectionHash = ItemSection::hashOf($section->markdown);

        if ($currentSectionHash === null) {
            throw new ContractException(ContractErrorCodeEnum::SectionNotSynced, 'This section has no stored markdown yet; re-sync the source before editing sections.');
        }

        if ($payload->baseSectionHash !== null) {
            $this->assertCurrent($payload->baseSectionHash, $currentSectionHash, 'base_section_hash');
        } else {
            $this->assertCurrent($payload->baseContentHash, $existingItem->content_hash, 'base_content_hash');
        }

        return [...$base, 'base_section_hash' => $currentSectionHash];
    }

    /**
     * @throws InvalidArgumentException When the author sent no base or an old one.
     */
    private function assertCurrent(?string $sentHash, string $currentHash, string $field): void
    {
        if ($sentHash === null) {
            throw new ContractException(ContractErrorCodeEnum::BaseRequired, $field.' is required: send the version you read.');
        }

        if (! hash_equals($currentHash, $sentHash)) {
            throw new ContractException(ContractErrorCodeEnum::StaleBase, 'The content changed since you read it ('.$field.' is stale); re-read and propose again.');
        }
    }
}
