<?php

declare(strict_types=1);

namespace App\Brain\Adapters;

use App\Brain\Data\ItemData;
use App\Brain\Data\ManifestData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Support\PathHelper;

/**
 * Native Instituta markdown trees (manifest present or native frontmatter keys).
 */
final class InstitutaAdapter extends MarkdownAdapter
{
    public function name(): string
    {
        return 'instituta';
    }

    public function supports(string $relativePath, string $contents): bool
    {
        if (PathHelper::extension($relativePath) !== 'md') {
            return false;
        }

        if (preg_match('/^---\s*\n(?:.*\n)*?(?:type|area|resolution_key|decision_date|effective_date|review_date):\s*/s', $contents) === 1) {
            return true;
        }

        return false;
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
            $items[] = new ItemData(
                path: $item->path,
                stableKey: $item->stableKey,
                title: $item->title,
                summary: $item->summary,
                type: $item->type,
                area: $item->area,
                visibility: $item->visibility,
                adapter: $this->name(),
                citation: $item->citation,
                resolutionKey: $item->resolutionKey,
                decisionDate: $item->decisionDate,
                effectiveDate: $item->effectiveDate,
                reviewDate: $item->reviewDate,
                tags: $item->tags,
                frontmatter: $item->frontmatter,
                sections: $item->sections,
                tables: $item->tables,
                edges: $item->edges,
                body: $item->body,
            );
        }

        return [
            'items' => $items,
            'skipped' => $result['skipped'],
            'issues' => $result['issues'],
        ];
    }
}
