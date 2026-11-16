<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Brain\Data\PerimeterData;
use App\Contracts\SearchIndexContract;
use App\Services\SearchHitHydratorService;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL full-text reference driver (tsvector / GIN / websearch_to_tsquery).
 * Vendor SQL stays in this class.
 */
final class PgsqlFullTextSearchDriver implements SearchIndexContract
{
    public function __construct(
        private readonly SearchHitHydratorService $hits = new SearchHitHydratorService,
    ) {}

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
        // Fetch more than asked: several sections of one item may match (#216).
        $bindings[] = $limit * SearchHitHydratorService::MAX_SECTIONS_PER_ITEM * 2;

        /** @var list<object{section_id: string, item_id: string, snippet: string, score: float|int|string}> $rows */
        $rows = DB::select(
            <<<SQL
            SELECT
                s.id AS section_id,
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

        return $this->hits->hydrate(array_map(static fn (object $row): array => [
            'section_id' => (string) $row->section_id,
            'item_id' => (string) $row->item_id,
            'snippet' => (string) $row->snippet,
            'score' => (float) $row->score,
        ], $rows), $limit);
    }
}
