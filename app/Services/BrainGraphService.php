<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\RoleEnum;
use App\Enums\GapReportStatusEnum;
use App\Enums\ProposalStatusEnum;
use App\Models\GapReport;
use App\Models\Item;
use App\Models\ItemChange;
use App\Models\ItemLink;
use App\Models\Proposal;
use Illuminate\Support\Carbon;

/**
 * The brain graph of the web UI (#254). MCP and REST keep their neighbours-only graph
 * (`instituta_graph`), which answers one item at a time.
 */
final class BrainGraphService
{
    /**
     * The whole brain in the perimeter as a graph (#254): every item a node with its number of
     * links, every resolved link between two items in the perimeter an edge, once per pair.
     * Unresolved links are left out (the health check lists them). Each node also says what waits
     * for review on it (#287): open proposals on the item, and for owners the open gap reports that
     * name it; and when its content last changed (the latest item change, else when it was added).
     *
     * @return array{nodes: list<array{id: string, title: string, stable_key: string, area: string, status: string, degree: int, open_proposals: int, open_gaps: int, changed_at: string|null}>, edges: list<array{from: string, to: string}>}
     */
    public function whole(PerimeterData $perimeter): array
    {
        $items = Item::query()
            ->withinPerimeter($perimeter)
            ->get(['id', 'title', 'stable_key', 'area', 'status', 'created_at'])
            ->keyBy('id');

        $edges = [];
        $degrees = [];

        foreach (ItemLink::query()->whereIn('from_item_id', $items->keys()->all())->whereNotNull('to_item_id')->get(['from_item_id', 'to_item_id']) as $link) {
            $from = (string) $link->from_item_id;
            $to = (string) $link->to_item_id;

            if ($from === $to || ! $items->has($to)) {
                continue;
            }

            $pairKey = strcmp($from, $to) < 0 ? $from.'|'.$to : $to.'|'.$from;

            if (isset($edges[$pairKey])) {
                continue;
            }

            $edges[$pairKey] = ['from' => $from, 'to' => $to];
            $degrees[$from] = ($degrees[$from] ?? 0) + 1;
            $degrees[$to] = ($degrees[$to] ?? 0) + 1;
        }

        $proposalsByKey = $this->openProposalsByKey($perimeter);
        $gapsByKey = $this->openGapsByKey($perimeter);
        $changedAt = $this->lastChangedAt($items->keys()->all());

        return [
            'nodes' => array_values($items->map(static fn (Item $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'stable_key' => $item->stable_key,
                'area' => $item->area,
                'status' => $item->status->value,
                'degree' => $degrees[$item->id] ?? 0,
                'open_proposals' => $proposalsByKey[$item->stable_key] ?? 0,
                'open_gaps' => $gapsByKey[$item->stable_key] ?? 0,
                'changed_at' => $changedAt[$item->id] ?? $item->created_at?->toIso8601String(),
            ])->all()),
            'edges' => array_values($edges),
        ];
    }

    /**
     * Open proposals in the perimeter's scope, counted per target stable key.
     *
     * @return array<string, int>
     */
    private function openProposalsByKey(PerimeterData $perimeter): array
    {
        $counts = [];

        foreach (Proposal::query()
            ->where('scope_type', $perimeter->scopeType)
            ->where('scope_id', $perimeter->scopeId)
            ->where('status', ProposalStatusEnum::Open)
            ->pluck('stable_key') as $stableKey) {
            $counts[(string) $stableKey] = ($counts[(string) $stableKey] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Open gap reports in the perimeter's scope, counted per item they name; owners only, as in
     * the Review tabs (#255).
     *
     * @return array<string, int>
     */
    private function openGapsByKey(PerimeterData $perimeter): array
    {
        if ($perimeter->role !== RoleEnum::Owner) {
            return [];
        }

        $counts = [];

        foreach (GapReport::query()
            ->where('scope_type', $perimeter->scopeType)
            ->where('scope_id', $perimeter->scopeId)
            ->where('status', GapReportStatusEnum::Open)
            ->get(['related']) as $gap) {
            $named = collect($gap->related ?? [])->pluck('stable_key')->unique();

            foreach ($named as $stableKey) {
                $counts[(string) $stableKey] = ($counts[(string) $stableKey] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * When each item's content last changed, from the item change log.
     *
     * @param  array<int, int|string>  $itemIds
     * @return array<string, string>
     */
    private function lastChangedAt(array $itemIds): array
    {
        return ItemChange::query()
            ->whereIn('item_id', $itemIds)
            ->groupBy('item_id')
            ->selectRaw('item_id, max(changed_at) as last_changed_at')
            ->get()
            ->mapWithKeys(static fn (ItemChange $change): array => [
                (string) $change->item_id => Carbon::parse((string) $change->getAttribute('last_changed_at'))->toIso8601String(),
            ])
            ->all();
    }
}
