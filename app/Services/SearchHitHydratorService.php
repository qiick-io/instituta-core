<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Item;
use App\Models\ItemSection;

/**
 * Turns raw section matches from a search driver into contract hits (#216): one hit per matching
 * section, with its anchor and heading, so an agent can open it with `get_section` directly. At
 * most {@see self::MAX_SECTIONS_PER_ITEM} sections per item, so one long item does not fill the
 * list. Shared by the SQLite and Postgres drivers, which only differ in how they match.
 */
final class SearchHitHydratorService
{
    public const int MAX_SECTIONS_PER_ITEM = 2;

    /**
     * @param  list<array{section_id: string, item_id: string, snippet: string, score: float}>  $matches  best first
     * @return list<array{
     *     id: string,
     *     title: string,
     *     summary: string,
     *     status: string,
     *     superseded_by: string|null,
     *     anchor: string|null,
     *     heading: string|null,
     *     snippet: string,
     *     score: float,
     *     citation: array{source: string, path: string, section: string|null, commit: string|null}
     * }>
     */
    public function hydrate(array $matches, int $limit): array
    {
        $kept = [];
        $sectionsPerItem = [];

        foreach ($matches as $match) {
            $count = $sectionsPerItem[$match['item_id']] ?? 0;
            if ($count >= self::MAX_SECTIONS_PER_ITEM) {
                continue;
            }

            $sectionsPerItem[$match['item_id']] = $count + 1;
            $kept[] = $match;

            if (count($kept) >= $limit) {
                break;
            }
        }

        if ($kept === []) {
            return [];
        }

        $items = Item::query()->whereIn('id', array_column($kept, 'item_id'))->get()->keyBy('id');
        $sections = ItemSection::query()
            ->whereIn('id', array_column($kept, 'section_id'))
            ->get(['id', 'anchor', 'heading'])
            ->keyBy('id');

        $hits = [];
        foreach ($kept as $match) {
            /** @var Item|null $item */
            $item = $items->get($match['item_id']);
            if ($item === null) {
                continue;
            }

            /** @var ItemSection|null $section */
            $section = $sections->get($match['section_id']);

            $hits[] = [
                'id' => $item->id,
                'title' => $item->title,
                'summary' => $item->summary ?? '',
                'status' => $item->status->value,
                'superseded_by' => $item->superseded_by,
                'anchor' => $section?->anchor,
                'heading' => $section?->heading,
                'snippet' => $match['snippet'],
                'score' => round($match['score'], 6),
                'citation' => [
                    'source' => $item->source_id,
                    'path' => $item->path,
                    'section' => $section?->anchor,
                    'commit' => $item->commit,
                ],
            ];
        }

        return $hits;
    }
}
