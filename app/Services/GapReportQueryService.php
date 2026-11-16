<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Enums\GapReportStatusEnum;
use App\Models\GapReport;

/**
 * Gap reports of the perimeter's own scope (#229), for the owners' Gaps page.
 */
final class GapReportQueryService
{
    public function findInPerimeter(PerimeterData $perimeter, string $gapReportId): ?GapReport
    {
        return GapReport::query()
            ->whereKey($gapReportId)
            ->where('scope_type', $perimeter->scopeType)
            ->where('scope_id', $perimeter->scopeId)
            ->first();
    }

    /**
     * @return list<array{id: string, kind: string, status: string, summary: string, question: string|null, related: list<array<string, mixed>>, reporter: string|null, proposal_id: string|null, created_at: string|null}>
     */
    public function listForUi(PerimeterData $perimeter, GapReportStatusEnum $status): array
    {
        return array_values(GapReport::query()
            ->with('reporter')
            ->where('scope_type', $perimeter->scopeType)
            ->where('scope_id', $perimeter->scopeId)
            ->where('status', $status)
            ->latest()
            ->get()
            ->map(static fn (GapReport $gapReport): array => [
                'id' => $gapReport->id,
                'kind' => $gapReport->kind->value,
                'status' => $gapReport->status->value,
                'summary' => $gapReport->summary,
                'question' => $gapReport->question,
                'related' => $gapReport->related ?? [],
                'reporter' => $gapReport->reporter?->name,
                'proposal_id' => $gapReport->proposal_id,
                'created_at' => $gapReport->created_at?->toDateTimeString(),
            ])
            ->all());
    }
}
