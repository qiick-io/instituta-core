<?php

declare(strict_types=1);

namespace App\Actions;

use App\Brain\Data\PerimeterData;
use App\Data\ReportGapData;
use App\Enums\ContractErrorCodeEnum;
use App\Enums\GapReportStatusEnum;
use App\Exceptions\ContractException;
use App\Models\ApiKey;
use App\Models\GapReport;
use App\Models\Item;
use App\Models\User;
use App\Notifications\GapReportedNotification;
use App\Services\ActivityEventLoggerService;
use App\Services\ContractAuditService;
use App\Services\NotifyOrganisationUsersService;
use App\Services\PerimeterScopeColumnsService;

/**
 * Files a gap report (#229). Open to any key that can read, guests included: a report changes
 * nothing in the brain. Related items must be inside the reporter's perimeter, so a report never
 * reveals or names what the reporter cannot read.
 */
final readonly class ReportGapAction
{
    public function __construct(
        private ContractAuditService $audit = new ContractAuditService,
        private ActivityEventLoggerService $activity = new ActivityEventLoggerService,
        private PerimeterScopeColumnsService $scopeColumns = new PerimeterScopeColumnsService,
        private NotifyOrganisationUsersService $notifier = new NotifyOrganisationUsersService,
    ) {}

    /**
     * Notifies the organisation's users (queued) and writes an activity entry.
     *
     * @throws ContractException When a related item is not in the perimeter, or the perimeter has no organisation.
     */
    public function execute(PerimeterData $perimeter, ?ApiKey $apiKey, ReportGapData $payload, string $surface): GapReport
    {
        [$organisationId, $companyId, $productId] = $this->scopeColumns->columnsFor($perimeter);

        if ($organisationId === null || $organisationId === '') {
            throw new ContractException(ContractErrorCodeEnum::OutOfPerimeter, 'A gap report needs a resolvable organisation scope.');
        }

        $related = array_map(fn (array $entry): array => $this->relatedEntry($perimeter, $entry['item'], $entry['anchor']), $payload->related);

        $gapReport = GapReport::query()->create([
            'organisation_id' => $organisationId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'scope_type' => $perimeter->scopeType,
            'scope_id' => $perimeter->scopeId,
            'kind' => $payload->kind,
            'status' => GapReportStatusEnum::Open,
            'summary' => $payload->summary,
            'question' => $payload->question,
            'related' => $related === [] ? null : $related,
            'reported_by_principal_id' => $perimeter->principalId,
            'api_key_id' => $apiKey?->id,
        ]);

        $this->audit->record($surface === 'mcp' ? 'instituta_report_gap' : 'POST /gaps', $perimeter, $apiKey, array_column($related, 'item'));

        $apiKey?->loadMissing('principal.user');
        $causer = $apiKey?->principal?->user;
        $this->activity->gapReport('reported', $gapReport, $causer instanceof User ? $causer : null, [
            'organisation_id' => $organisationId,
            'gap_report_id' => $gapReport->id,
            'kind' => $payload->kind->value,
        ]);

        $this->notifier->notify($organisationId, new GapReportedNotification($gapReport));

        return $gapReport;
    }

    /**
     * @return array{item: string, stable_key: string, path: string, title: string, anchor: string|null}
     *
     * @throws ContractException When the item (or its anchor) is not in the perimeter.
     */
    private function relatedEntry(PerimeterData $perimeter, string $itemReference, ?string $anchor): array
    {
        $item = Item::query()
            ->withinPerimeter($perimeter)
            ->matchingReference($itemReference)
            ->first();

        if ($item === null || ($anchor !== null && ! $item->sections()->where('anchor', $anchor)->exists())) {
            throw new ContractException(ContractErrorCodeEnum::NotFound, 'A related item or section is not in your perimeter: '.$itemReference.($anchor !== null ? '#'.$anchor : '').'.');
        }

        return ['item' => $item->id, 'stable_key' => $item->stable_key, 'path' => $item->path, 'title' => $item->title, 'anchor' => $anchor];
    }
}
