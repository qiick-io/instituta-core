<?php

declare(strict_types=1);

namespace App\Brain\Adapters;

use App\Brain\Contracts\BrainAdapterContract;
use App\Brain\Data\CitationData;
use App\Brain\Data\ItemData;
use App\Brain\Data\ManifestData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Data\SkippedFileData;
use App\Brain\Data\StructuredTableData;
use App\Brain\Data\ValidationIssueData;
use App\Brain\Enums\VisibilityEnum;
use App\Brain\Support\PathHelper;

/**
 * shadcn-style registry.json → one item per registry entry + a structured table.
 */
final class ShadcnRegistryAdapter implements BrainAdapterContract
{
    public function name(): string
    {
        return 'shadcn-registry';
    }

    public function supports(string $relativePath, string $contents): bool
    {
        return strtolower(basename($relativePath)) === 'registry.json';
    }

    public function readFile(
        string $absolutePath,
        string $relativePath,
        string $contents,
        ReadRequestData $request,
        ?ManifestData $manifest,
    ): array {
        unset($absolutePath);
        $issues = [];
        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            return [
                'items' => [],
                'skipped' => [
                    new SkippedFileData($relativePath, 'invalid_registry_json'),
                ],
                'issues' => [
                    new ValidationIssueData(
                        path: $relativePath,
                        code: 'registry_json_invalid',
                        message: 'registry.json is not valid JSON.',
                    ),
                ],
            ];
        }

        $registryName = is_string($decoded['name'] ?? null) ? $decoded['name'] : PathHelper::filename($relativePath);
        $entries = $decoded['items'] ?? $decoded['registry'] ?? [];
        if (! is_array($entries)) {
            $entries = [];
        }

        $rows = [];
        $items = [];
        $position = 0;
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = is_string($entry['name'] ?? null) ? $entry['name'] : null;
            if ($name === null || $name === '') {
                continue;
            }
            $type = is_string($entry['type'] ?? null) ? $entry['type'] : 'component';
            $rows[] = [
                'name' => $name,
                'type' => $type,
                'title' => is_string($entry['title'] ?? null) ? $entry['title'] : $name,
                'description' => is_string($entry['description'] ?? null) ? $entry['description'] : '',
            ];

            $itemPath = rtrim(dirname($relativePath), './').'/'.$name;
            $itemPath = ltrim(str_replace('\\', '/', $itemPath), '/');
            if (str_starts_with($itemPath, '/')) {
                $itemPath = substr($itemPath, 1);
            }
            if ($relativePath === 'registry.json') {
                $itemPath = 'registry/'.$name;
            }

            $items[] = new ItemData(
                path: $itemPath,
                stableKey: $itemPath,
                title: is_string($entry['title'] ?? null) ? $entry['title'] : $name,
                summary: is_string($entry['description'] ?? null)
                    ? $entry['description']
                    : sprintf('Registry component %s from %s.', $name, $registryName),
                type: 'component',
                area: 'design-system',
                visibility: $manifest !== null ? $manifest->defaultVisibility : VisibilityEnum::Internal,
                adapter: $this->name(),
                citation: new CitationData(
                    source: $request->source,
                    path: $relativePath,
                    section: $name,
                    commit: $request->commit,
                ),
                resolutionKey: null,
                tags: is_array($entry['categories'] ?? null)
                    ? array_values(array_filter($entry['categories'], 'is_string'))
                    : [],
                frontmatter: $entry,
                sections: [],
                tables: [],
                edges: [],
                body: '',
            );
            $position++;
        }

        $table = new StructuredTableData(
            tableKey: 'registry',
            columns: [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'type', 'label' => 'Type'],
                ['key' => 'title', 'label' => 'Title'],
                ['key' => 'description', 'label' => 'Description'],
            ],
            values: $rows,
            position: 0,
        );

        $registryItem = new ItemData(
            path: $relativePath,
            stableKey: $this->stableKeyFor($relativePath),
            title: $registryName,
            summary: sprintf('shadcn registry with %d items.', count($rows)),
            type: 'token-set',
            area: 'design-system',
            visibility: $manifest !== null ? $manifest->defaultVisibility : VisibilityEnum::Internal,
            adapter: $this->name(),
            citation: new CitationData(
                source: $request->source,
                path: $relativePath,
                section: null,
                commit: $request->commit,
            ),
            sections: [],
            tables: [$table],
            edges: [],
            body: '',
        );

        return [
            'items' => [$registryItem, ...$items],
            'skipped' => [],
            'issues' => $issues,
        ];
    }

    private function stableKeyFor(string $relativePath): string
    {
        return preg_replace('/\.json$/i', '', str_replace('\\', '/', $relativePath)) ?? $relativePath;
    }
}
