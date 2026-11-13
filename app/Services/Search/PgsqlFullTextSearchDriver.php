<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Brain\Data\PerimeterData;
use App\Contracts\SearchIndexContract;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL full-text reference driver (tsvector / GIN / websearch_to_tsquery).
 * Vendor SQL stays in this class.
 */
final class PgsqlFullTextSearchDriver implements SearchIndexContract
{
    public function search(
        PerimeterData $perimeter,
        string $query,
        array $allowedItemIds,
        ?string $area = null,
        int $limit = 20,
    ): array {
        unset($perimeter);

        if ($allowedItemIds === [] || trim($query) === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $placeholders = implode(',', array_fill(0, count($allowedItemIds), '?'));

        $bindings = [$query, $query, $query, ...$allowedItemIds];
        $areaSql = '';
        if ($area !== null && $area !== '') {
            $areaSql = ' AND i.area = ?';
            $bindings[] = $area;
        }
        $bindings[] = $limit;

        /** @var list<object{item_id: string, snippet: string, score: float|int|string}> $rows */
        $rows = DB::select(
            <<<SQL
            SELECT
                s.item_id AS item_id,
                ts_headline(
                    'english',
                    s.body,
                    websearch_to_tsquery('english', ?),
                    'MaxWords=20, MinWords=5, MaxFragments=1'
                ) AS snippet,
                ts_rank(s.search_vector, websearch_to_tsquery('english', ?)) AS score
            FROM item_sections s
            INNER JOIN items i ON i.id = s.item_id
            WHERE s.search_vector @@ websearch_to_tsquery('english', ?)
              AND s.item_id IN ({$placeholders})
              {$areaSql}
            ORDER BY score DESC
            LIMIT ?
            SQL,
            $bindings,
        );

        return $this->hydrateHits($rows);
    }

    /**
     * @param  list<object{item_id: string, snippet: string, score: float|int|string}>  $rows
     * @return list<array{
     *     id: string,
     *     title: string,
     *     summary: string,
     *     snippet: string,
     *     score: float,
     *     citation: array{source: string, path: string, section: string|null, commit: string|null}
     * }>
     */
    private function hydrateHits(array $rows): array
    {
        $best = [];
        foreach ($rows as $row) {
            $itemId = (string) $row->item_id;
            if (isset($best[$itemId])) {
                continue;
            }
            $best[$itemId] = [
                'snippet' => (string) $row->snippet,
                'score' => round((float) $row->score, 6),
            ];
        }

        if ($best === []) {
            return [];
        }

        $items = Item::query()
            ->whereIn('id', array_keys($best))
            ->get()
            ->keyBy('id');

        $hits = [];
        foreach ($best as $itemId => $meta) {
            /** @var Item|null $item */
            $item = $items->get($itemId);
            if ($item === null) {
                continue;
            }

            $hits[] = [
                'id' => $item->id,
                'title' => $item->title,
                'summary' => $item->summary ?? '',
                'snippet' => $meta['snippet'],
                'score' => $meta['score'],
                'citation' => [
                    'source' => $item->source_id,
                    'path' => $item->path,
                    'section' => null,
                    'commit' => $item->commit,
                ],
            ];
        }

        return $hits;
    }
}
