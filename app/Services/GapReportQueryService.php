<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Enums\GapReportStatusEnum;
use App\Models\GapReport;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Gap reports of the perimeter's own scope (#229), for the owners' Gaps page, within its area
 * and path limits (#334).
 */
final class GapReportQueryService
{
    public function findInPerimeter(PerimeterData $perimeter, string $gapReportId): ?GapReport
    {
        return $this->visibleIn($perimeter, GapReport::query()->whereKey($gapReportId))->first();
    }

    /**
     * Open gap reports the perimeter may see, for the review badge.
     */
    public function openCount(PerimeterData $perimeter): int
    {
        return $this->visibleIn($perimeter, GapReport::query()->where('status', GapReportStatusEnum::Open))->count();
    }

    /**
     * The scope's gap reports from a query, within the area and path limits (#334): under a limit,
     * a report naming items shows only when one of them is inside, and lists only those; a report
     * naming no item is about missing knowledge and shows.
     *
     * @param  Builder<GapReport>  $query
     * @return Collection<int, GapReport>
     */
    public function visibleIn(PerimeterData $perimeter, Builder $query): Collection
    {
        $gapReports = $query
            ->where('scope_type', $perimeter->scopeType)
            ->where('scope_id', $perimeter->scopeId)
            ->get();

        if (! $perimeter->isLimited()) {
            return $gapReports;
        }

        $relatedItemIds = $gapReports->flatMap(static fn (GapReport $gapReport): array => array_column($gapReport->related ?? [], 'item'))->unique()->values()->all();
        $visibleItemIds = array_flip(Item::query()->withinPerimeter($perimeter)->whereKey($relatedItemIds)->pluck('id')->all());

        $visibleRelated = static fn (GapReport $gapReport): array => array_values(array_filter(
            $gapReport->related ?? [],
            static fn (array $related): bool => isset($visibleItemIds[$related['item']]),
        ));

        return $gapReports
            ->filter(static fn (GapReport $gapReport): bool => ($gapReport->related ?? []) === [] || $visibleRelated($gapReport) !== [])
            ->each(static function (GapReport $gapReport) use ($visibleRelated): void {
                if (($gapReport->related ?? []) !== []) {
                    $gapReport->related = $visibleRelated($gapReport);
                }
            })
            ->values();
    }

    /**
     * @return list<array{id: string, kind: string, status: string, summary: string, question: string|null, related: list<array<string, mixed>>, reporter: string|null, proposal_id: string|null, created_at: string|null}>
     */
    public function listForUi(PerimeterData $perimeter, GapReportStatusEnum $status): array
    {
        return array_values($this->visibleIn($perimeter, GapReport::query()
            ->with('reporter')
            ->where('status', $status)
            ->latest())
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
