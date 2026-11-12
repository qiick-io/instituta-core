<?php

declare(strict_types=1);

namespace App\Brain\Readers;

use App\Brain\Data\BrainRepositoryData;
use App\Brain\Data\ItemData;
use App\Brain\Data\LinkEdgeData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Data\ValidationIssueData;

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
        $needle = strtolower(trim($q));
        $hits = [];
        foreach ($repo->items as $item) {
            if ($area !== null && $item->area !== $area) {
                continue;
            }
            $hay = strtolower($item->title.' '.$item->summary.' '.$item->body);
            if ($needle !== '' && ! str_contains($hay, $needle)) {
                continue;
            }
            $row = $this->summary($item, $repo);
            $row['score'] = $needle === '' ? 0.0 : (str_contains(strtolower($item->title), $needle) ? 1.0 : 0.5);
            $row['snippet'] = mb_substr($item->summary, 0, 160);
            $hits[] = $row;
        }

        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

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
            'sections' => array_map(static fn ($section) => [
                'anchor' => $section->anchor,
                'heading' => $section->heading,
                'level' => $section->level,
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
        $out = [];
        foreach ($repo->items as $item) {
            if ($item->resolutionKey === null || $item->resolutionKey === '') {
                continue;
            }
            if ($area !== null && $item->area !== $area) {
                continue;
            }
            $out[] = [
                'resolution_key' => $item->resolutionKey,
                'title' => $item->title,
                'summary' => $item->summary,
                'visibility' => $item->visibility->value,
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

        usort($out, static fn (array $a, array $b): int => strcmp($a['resolution_key'], $b['resolution_key']));

        return $out;
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
