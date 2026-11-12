<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Brain\Data\PerimeterData;
use App\Contracts\SearchIndexContract;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * SQLite FTS5 driver for default install and Pest.
 * Vendor SQL stays in this class.
 */
final class SqliteFts5SearchDriver implements SearchIndexContract
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
        $match = $this->toMatchQuery($query);
        if ($match === '') {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($allowedItemIds), '?'));
        $bindings = [$match, ...$allowedItemIds];

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
                f.item_id AS item_id,
                snippet(item_sections_fts, 8, '', '', '…', 12) AS snippet,
                bm25(item_sections_fts) AS score
            FROM item_sections_fts f
            INNER JOIN items i ON i.id = f.item_id
            WHERE item_sections_fts MATCH ?
              AND f.item_id IN ({$placeholders})
              {$areaSql}
            ORDER BY score
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
                // bm25 is lower-is-better; invert for a positive score surface.
                'score' => round(1 / (1 + abs((float) $row->score)), 6),
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

    private function toMatchQuery(string $query): string
    {
        $tokens = preg_split('/\s+/', strtolower(trim($query))) ?: [];
        $safe = [];
        foreach ($tokens as $token) {
            $token = preg_replace('/[^a-z0-9_-]/i', '', $token) ?? '';
            if ($token === '') {
                continue;
            }
            $safe[] = $token.'*';
        }

        return implode(' ', $safe);
    }
}
