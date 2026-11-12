<?php

declare(strict_types=1);

namespace App\Brain\Adapters;

use App\Brain\Data\ItemData;
use App\Brain\Data\LinkEdgeData;
use App\Brain\Data\ManifestData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Support\PathHelper;

/**
 * Basic Memory markdown: frontmatter, ## Observations, ## Relations.
 */
final class BasicMemoryAdapter extends MarkdownAdapter
{
    public function name(): string
    {
        return 'basic-memory';
    }

    public function supports(string $relativePath, string $contents): bool
    {
        if (PathHelper::extension($relativePath) !== 'md') {
            return false;
        }

        return preg_match('/^##\s+Observations\s*$/mi', $contents) === 1
            || preg_match('/^##\s+Relations\s*$/mi', $contents) === 1;
    }

    public function readFile(
        string $absolutePath,
        string $relativePath,
        string $contents,
        ReadRequestData $request,
        ?ManifestData $manifest,
    ): array {
        $result = parent::readFile($absolutePath, $relativePath, $contents, $request, $manifest);
        $items = [];

        foreach ($result['items'] as $item) {
            $observationTags = $this->observationTags($item->body);
            $relationEdges = $this->relationEdges($item->body, $relativePath);
            $edges = $this->mergeEdges($item->edges, $relationEdges);
            $tags = array_values(array_unique([...$item->tags, ...$observationTags]));

            $items[] = new ItemData(
                path: $item->path,
                stableKey: $item->stableKey,
                title: $item->title,
                summary: $item->summary,
                type: $item->type !== 'note' ? $item->type : 'note',
                area: $item->area,
                visibility: $item->visibility,
                adapter: $this->name(),
                citation: $item->citation,
                resolutionKey: $item->resolutionKey,
                decisionDate: $item->decisionDate,
                effectiveDate: $item->effectiveDate,
                reviewDate: $item->reviewDate,
                tags: $tags,
                frontmatter: [
                    ...$item->frontmatter,
                    'observations' => $this->observations($item->body),
                ],
                sections: $item->sections,
                tables: $item->tables,
                edges: $edges,
                body: $item->body,
            );
        }

        return [
            'items' => $items,
            'skipped' => $result['skipped'],
            'issues' => $result['issues'],
        ];
    }

    /**
     * @return list<array{category: string, text: string, tags: list<string>}>
     */
    private function observations(string $body): array
    {
        $section = $this->sectionBody($body, 'Observations');
        if ($section === null) {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\r?\n/', $section) ?: [] as $line) {
            if (preg_match('/^-\s+\[([^\]]+)\]\s+(.+?)(?:\s+#(\S+(?:\s+#\S+)*))?\s*$/', trim($line), $matches) !== 1) {
                continue;
            }
            $tagString = $matches[3] ?? '';
            $tags = $tagString === ''
                ? []
                : array_values(array_filter(array_map(
                    static fn (string $t): string => ltrim($t, '#'),
                    preg_split('/\s+/', $tagString) ?: [],
                )));
            $rows[] = [
                'category' => trim($matches[1]),
                'text' => trim($matches[2]),
                'tags' => $tags,
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function observationTags(string $body): array
    {
        $tags = [];
        foreach ($this->observations($body) as $row) {
            foreach ($row['tags'] as $tag) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * @return list<LinkEdgeData>
     */
    private function relationEdges(string $body, string $relativePath): array
    {
        $section = $this->sectionBody($body, 'Relations');
        if ($section === null) {
            return [];
        }

        $edges = [];
        foreach (preg_split('/\r?\n/', $section) ?: [] as $line) {
            if (preg_match('/^-\s+(\S+)\s+\[\[([^\]]+)\]\]\s*$/', trim($line), $matches) !== 1) {
                continue;
            }
            $edges[] = new LinkEdgeData(
                fromPath: $relativePath,
                target: trim($matches[2]),
                relation: trim($matches[1]),
                fromSection: 'relations',
            );
        }

        return $edges;
    }

    private function sectionBody(string $body, string $heading): ?string
    {
        if (preg_match('/^##\s+'.preg_quote($heading, '/').'\s*$\n(.*?)(?=^##\s+|\z)/msi', $body, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * @param  list<LinkEdgeData>  $base
     * @param  list<LinkEdgeData>  $extra
     * @return list<LinkEdgeData>
     */
    private function mergeEdges(array $base, array $extra): array
    {
        $seen = [];
        $out = [];
        foreach ([...$base, ...$extra] as $edge) {
            $key = $edge->fromPath.'|'.$edge->relation.'|'.$edge->target;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $edge;
        }

        return $out;
    }
}
