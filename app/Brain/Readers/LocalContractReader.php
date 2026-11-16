<?php

declare(strict_types=1);

namespace App\Brain\Readers;

use App\Brain\Data\BrainRepositoryData;
use App\Brain\Data\ItemData;
use App\Brain\Data\LinkEdgeData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Data\ValidationIssueData;
use App\Brain\Enums\ItemStatusEnum;

/**
 * Folder-backed contract operations (CLI / stdio MCP). No database.
 */
final class LocalContractReader
{
    public function __construct(
        private readonly BrainReader $reader = new BrainReader,
    ) {}

    public function open(ReadRequestData $request): BrainRepositoryData
    {
        return $this->reader->read($request);
    }

    /**
     * @return list<ValidationIssueData>
     */
    public function validate(ReadRequestData $request): array
    {
        return $this->reader->validate($request);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(BrainRepositoryData $repo, ?string $area = null, ?string $type = null): array
    {
        $out = [];
        foreach ($repo->items as $item) {
            if ($area !== null && $item->area !== $area) {
                continue;
            }
            if ($type !== null && $item->type !== $type) {
                continue;
            }
            $out[] = $this->summary($item, $repo);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(BrainRepositoryData $repo, string $q, ?string $area = null, int $limit = 20): array
    {
        $terms = array_values(array_filter(
            preg_split('/\s+/', mb_strtolower(trim($q))) ?: [],
            static fn (string $term): bool => mb_strlen($term) > 1,
        ));
        $hits = [];

        foreach ($repo->items as $item) {
            if ($area !== null && $item->area !== $area) {
                continue;
            }

            $sectionHits = [];
            foreach ($item->sections as $section) {
                $haystack = mb_strtolower($item->title.' '.$section->heading.' '.$section->body);
                $matched = $terms === [] ? 0 : count(array_filter($terms, static fn (string $term): bool => str_contains($haystack, $term)));

                if ($matched === 0) {
                    continue;
                }

                // Any term matches (#217); more matched terms and a title match rank higher.
                $titleBonus = str_contains(mb_strtolower($item->title), $terms[0]) ? 0.25 : 0.0;
                $sectionHits[] = [
                    ...$this->summary($item, $repo),
                    'anchor' => $section->anchor,
                    'heading' => $section->heading,
                    'score' => round($matched / count($terms) + $titleBonus, 6),
                    'snippet' => mb_substr($section->body, 0, 160),
                    'citation' => [...$item->citation->toArray(), 'section' => $section->anchor],
                ];
            }

            usort($sectionHits, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
            // One hit per matching section (#216), at most two per item.
            array_push($hits, ...array_slice($sectionHits, 0, 2));
        }

        usort($hits, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_slice($hits, 0, max(1, min(50, $limit)));
    }

    public function find(BrainRepositoryData $repo, string $id): ?ItemData
    {
        foreach ($repo->items as $item) {
            if ($item->path === $id || $item->stableKey === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(BrainRepositoryData $repo, string $id): ?array
    {
        $item = $this->find($repo, $id);
        if ($item === null) {
            return null;
        }

        return [
            ...$this->summary($item, $repo),
            'sections' => array_map(static fn ($s) => $s->toArray(), $item->sections),
            'tables' => array_map(static fn ($t) => $t->toArray(), $item->tables),
            'body' => $item->body,
        ];
    }

    /**
     * One section of an item, so local agents read only what they need, as over HTTP (#202).
     *
     * @return array<string, mixed>|null
     */
    public function getSection(BrainRepositoryData $repo, string $id, string $anchor): ?array
    {
        $item = $this->find($repo, $id);
        if ($item === null) {
            return null;
        }

        foreach ($item->sections as $section) {
            if ($section->anchor === $anchor) {
                return [
                    'id' => $item->stableKey,
                    'path' => $item->path,
                    ...$section->toArray(),
                    'citation' => [...$item->citation->toArray(), 'section' => $section->anchor],
                ];
            }
        }

        return null;
    }

    /**
     * Several sections in one call, as over HTTP (#225): a missing item or anchor answers
     * `error: not_found` on its own entry (the contract code, kept literal so the brain module
     * stays independent of the app).
     *
     * @param  list<array{item: string, anchor: string}>  $requestedSections
     * @return array{sections: list<array<string, mixed>>}
     */
    public function getSections(BrainRepositoryData $repo, array $requestedSections): array
    {
        $answeredSections = [];

        foreach ($requestedSections as $requestedSection) {
            $section = $this->getSection($repo, $requestedSection['item'], $requestedSection['anchor']);

            $answeredSections[] = [
                'item' => $requestedSection['item'],
                'anchor' => $requestedSection['anchor'],
                ...($section === null ? ['error' => 'not_found'] : ['section' => $section]),
            ];
        }

        return ['sections' => $answeredSections];
    }

    /**
     * The rows of one structured table of an item (#202).
     *
     * @return array<string, mixed>|null
     */
    public function table(BrainRepositoryData $repo, string $id, string $tableKey): ?array
    {
        $item = $this->find($repo, $id);
        if ($item === null) {
            return null;
        }

        $rows = array_values(array_filter($item->tables, static fn ($table): bool => $table->tableKey === $tableKey));
        if ($rows === []) {
            return null;
        }

        return [
            'id' => $item->stableKey,
            'table_key' => $tableKey,
            'columns' => $rows[0]->columns,
            'rows' => array_map(static fn ($row): array => $row->values, $rows),
            'citation' => $item->citation->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function outline(BrainRepositoryData $repo, string $id): ?array
    {
        $item = $this->find($repo, $id);
        if ($item === null) {
            return null;
        }

        return [
            'id' => $item->stableKey,
            'path' => $item->path,
            'token_estimate' => $item->tokenEstimate(),
            'sections' => array_map(static fn ($section) => [
                'anchor' => $section->anchor,
                'heading' => $section->heading,
                'level' => $section->level,
                'token_estimate' => $section->tokenEstimate(),
                'block_types' => array_map(static fn ($b) => $b->type->value, $section->blocks),
            ], $item->sections),
            'citation' => $item->citation->toArray(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function graph(BrainRepositoryData $repo, string $id): array
    {
        $item = $this->find($repo, $id);
        if ($item === null) {
            return [];
        }

        $neighbours = [];
        foreach ($repo->edges as $edge) {
            if ($edge->fromPath === $item->path) {
                $neighbours[] = $this->edgeArray($edge);
            }
        }
        foreach ($item->edges as $edge) {
            $neighbours[] = $this->edgeArray($edge);
        }

        $seen = [];
        $unique = [];
        foreach ($neighbours as $row) {
            $key = $row['relation'].'|'.$row['target'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $row;
        }

        return $unique;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resolve(BrainRepositoryData $repo, ?string $area = null): array
    {
        /** @var array<string, array<string, mixed>> $byKey */
        $byKey = [];

        foreach ($repo->items as $item) {
            if ($item->resolutionKey === null || $item->resolutionKey === '') {
                continue;
            }
            if ($area !== null && $item->area !== $area) {
                continue;
            }

            $status = ItemStatusEnum::fromFrontmatter($item->frontmatter);

            // An active entry always wins over an inactive one with the same key (#218).
            if (! $status->isCurrent() && ($byKey[$item->resolutionKey]['status'] ?? null) === ItemStatusEnum::Active->value) {
                continue;
            }

            $byKey[$item->resolutionKey] = [
                'resolution_key' => $item->resolutionKey,
                'title' => $item->title,
                'summary' => $item->summary,
                'visibility' => $item->visibility->value,
                ...$this->validity($item),
                'origin' => [
                    'scope_type' => 'product',
                    'scope_id' => null,
                    'source' => $repo->source,
                    'path' => $item->path,
                    'commit' => $repo->commit,
                ],
                'citation' => $item->citation->toArray(),
            ];
        }

        ksort($byKey);

        return array_values($byKey);
    }

    /**
     * Whether the entry is still valid (#218), from its frontmatter.
     *
     * @return array{status: string, superseded_by: string|null, decision_date: string|null, effective_date: string|null, review_date: string|null}
     */
    private function validity(ItemData $item): array
    {
        $supersededBy = $item->frontmatter['superseded_by'] ?? null;

        return [
            'status' => ItemStatusEnum::fromFrontmatter($item->frontmatter)->value,
            'superseded_by' => is_string($supersededBy) && trim($supersededBy) !== '' ? trim($supersededBy) : null,
            'decision_date' => $item->decisionDate,
            'effective_date' => $item->effectiveDate,
            'review_date' => $item->reviewDate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ItemData $item, BrainRepositoryData $repo): array
    {
        return [
            'id' => $item->stableKey,
            'stable_key' => $item->stableKey,
            'title' => $item->title,
            'summary' => $item->summary,
            'area' => $item->area,
            'type' => $item->type,
            'visibility' => $item->visibility->value,
            'token_estimate' => $item->tokenEstimate(),
            ...$this->validity($item),
            'path' => $item->path,
            'adapter' => $item->adapter,
            'origin' => [
                'scope_type' => 'product',
                'scope_id' => null,
                'source' => $repo->source,
                'path' => $item->path,
                'commit' => $repo->commit,
            ],
            'citation' => $item->citation->toArray(),
        ];
    }

    /**
     * @return array{from_path: string, target: string, relation: string, from_section: string|null}
     */
    private function edgeArray(LinkEdgeData $edge): array
    {
        return $edge->toArray();
    }
}
