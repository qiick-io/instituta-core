<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Brain\Data\PerimeterData;
use App\Contracts\SearchIndexContract;
use App\Services\SearchHitHydratorService;
use Illuminate\Support\Facades\DB;

/**
 * SQLite FTS5 driver for default install and Pest.
 * Vendor SQL stays in this class.
 */
final class SqliteFts5SearchDriver implements SearchIndexContract
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

        // Fetch more than asked: several sections of one item may match (#216).
        $bindings[] = $limit * SearchHitHydratorService::MAX_SECTIONS_PER_ITEM * 2;

        /** @var list<object{section_id: string, item_id: string, snippet: string, score: float|int|string}> $rows */
        $rows = DB::select(
            <<<SQL
            SELECT
                f.section_id AS section_id,
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

        return $this->hits->hydrate(array_map(static fn (object $row): array => [
            'section_id' => (string) $row->section_id,
            'item_id' => (string) $row->item_id,
            'snippet' => (string) $row->snippet,
            // bm25 is lower-is-better; invert for a positive score surface.
            'score' => 1 / (1 + abs((float) $row->score)),
        ], $rows), $limit);
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

        // Any term (#217): BM25 still ranks sections matching more terms first.
        return implode(' OR ', $safe);
    }
}
