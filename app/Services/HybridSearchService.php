<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Models\ItemSection;

/**
 * One search that finds a section by its words or by its meaning (#217). Keyword hits come from
 * the search driver; when embeddings are on, semantic hits from {@see BrainEmbeddingService}; the
 * two rankings are merged with reciprocal rank fusion (k = 60), which needs no score calibration
 * between BM25 / ts_rank and cosine similarity.
 */
final class HybridSearchService
{
    public const string MODE_HYBRID = 'hybrid';

    public const string MODE_KEYWORD = 'keyword';

    public const string MODE_SEMANTIC = 'semantic';

    private const int RRF_K = 60;

    public function __construct(
        private readonly BrainEmbeddingService $embeddings,
        private readonly SearchHitHydratorService $hydrator = new SearchHitHydratorService,
    ) {}

    public static function isSemanticAvailable(): bool
    {
        return (bool) config('ai.embeddings.enabled');
    }

    /**
     * Semantic hits as contract hits (snippet from the section body), inside the allowed items.
     *
     * @param  list<string>  $allowedItemIds
     * @return list<array<string, mixed>>
     */
    public function semanticHits(PerimeterData $perimeter, string $query, array $allowedItemIds, ?string $area, int $limit): array
    {
        if (! self::isSemanticAvailable()) {
            return [];
        }

        $allowed = array_flip($allowedItemIds);
        $results = array_values(array_filter(
            $this->embeddings->similar($perimeter, $query, $limit * 2),
            static fn (array $row): bool => isset($allowed[$row['item_id']]),
        ));

        if ($results === []) {
            return [];
        }

        $sections = ItemSection::query()
            ->whereIn('id', array_column($results, 'section_id'))
            ->with('item:id,area')
            ->get(['id', 'item_id', 'body'])
            ->keyBy('id');

        $matches = [];
        foreach ($results as $row) {
            /** @var ItemSection|null $section */
            $section = $sections->get($row['section_id']);

            if ($section === null || ($area !== null && $area !== '' && $section->item?->area !== $area)) {
                continue;
            }

            $matches[] = [
                'section_id' => (string) $row['section_id'],
                'item_id' => (string) $row['item_id'],
                'snippet' => mb_substr(trim((string) $section->body), 0, 160),
                'score' => (float) $row['score'],
            ];
        }

        return $this->hydrator->hydrate($matches, $limit);
    }

    /**
     * Reciprocal rank fusion of two best-first hit lists, keyed by item + section; each hit says
     * whether it matched by `keyword`, `semantic` or `both`.
     *
     * @param  list<array<string, mixed>>  $keywordHits
     * @param  list<array<string, mixed>>  $semanticHits
     * @return list<array<string, mixed>>
     */
    public function fuse(array $keywordHits, array $semanticHits, int $limit): array
    {
        $fused = [];

        foreach (['keyword' => $keywordHits, 'semantic' => $semanticHits] as $source => $hits) {
            foreach ($hits as $rank => $hit) {
                $key = $hit['id'].'#'.($hit['anchor'] ?? '');
                $contribution = 1 / (self::RRF_K + $rank + 1);

                if (isset($fused[$key])) {
                    $fused[$key]['score'] += $contribution;
                    $fused[$key]['match'] = 'both';

                    continue;
                }

                $fused[$key] = [...$hit, 'score' => $contribution, 'match' => $source];
            }
        }

        $ordered = array_values($fused);
        usort($ordered, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        $perItem = [];
        $kept = [];
        foreach ($ordered as $hit) {
            $count = $perItem[$hit['id']] ?? 0;
            if ($count >= SearchHitHydratorService::MAX_SECTIONS_PER_ITEM) {
                continue;
            }

            $perItem[$hit['id']] = $count + 1;
            $hit['score'] = round($hit['score'], 6);
            $kept[] = $hit;

            if (count($kept) >= $limit) {
                break;
            }
        }

        return $kept;
    }
}
