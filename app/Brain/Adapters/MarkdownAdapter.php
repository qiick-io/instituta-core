<?php

declare(strict_types=1);

namespace App\Brain\Adapters;

use App\Brain\Contracts\BrainAdapterContract;
use App\Brain\Data\CitationData;
use App\Brain\Data\ItemData;
use App\Brain\Data\ManifestData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Data\ValidationIssueData;
use App\Brain\Enums\VisibilityEnum;
use App\Brain\Support\FrontmatterParser;
use App\Brain\Support\MarkdownStructureParser;
use App\Brain\Support\PathHelper;

class MarkdownAdapter implements BrainAdapterContract
{
    public function __construct(
        private readonly FrontmatterParser $frontmatterParser = new FrontmatterParser,
        private readonly MarkdownStructureParser $structureParser = new MarkdownStructureParser,
    ) {}

    public function name(): string
    {
        return 'markdown';
    }

    public function supports(string $relativePath, string $contents): bool
    {
        return PathHelper::extension($relativePath) === 'md';
    }

    public function readFile(
        string $absolutePath,
        string $relativePath,
        string $contents,
        ReadRequestData $request,
        ?ManifestData $manifest,
    ): array {
        unset($absolutePath);

        $parsed = $this->frontmatterParser->parse($contents);
        $frontmatter = $parsed['frontmatter'];
        $body = $parsed['body'];

        $structure = $this->structureParser->parse($body, $relativePath);
        $issues = [];
        foreach ($structure['issues'] as $issue) {
            $issues[] = new ValidationIssueData(
                path: $issue['path'],
                code: $issue['code'],
                message: $issue['message'],
            );
        }

        if ($parsed['parse_error']) {
            $issues[] = new ValidationIssueData(
                path: $relativePath,
                code: 'frontmatter_unparseable',
                message: 'Frontmatter fences were present but YAML could not be parsed.',
            );
        }

        $visibility = $this->visibility($frontmatter, $manifest);
        if (isset($frontmatter['visibility']) && VisibilityEnum::tryFromLoose($frontmatter['visibility']) === null) {
            $issues[] = new ValidationIssueData(
                path: $relativePath,
                code: 'visibility_invalid',
                message: 'Unknown visibility value.',
            );
        }

        $title = $this->stringField($frontmatter, 'title')
            ?? $this->firstHeading($body)
            ?? PathHelper::filename($relativePath);

        $summary = $this->stringField($frontmatter, 'summary')
            ?? $this->structureParser->firstParagraphSummary($body);

        $stableKey = $this->stringField($frontmatter, 'permalink')
            ?? $this->stringField($frontmatter, 'key')
            ?? $this->defaultStableKey($relativePath);

        $type = $this->stringField($frontmatter, 'type') ?? 'note';
        $area = $this->stringField($frontmatter, 'area') ?? $this->areaFromPath($relativePath);

        $tags = [];
        if (isset($frontmatter['tags']) && is_array($frontmatter['tags'])) {
            foreach ($frontmatter['tags'] as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        $item = new ItemData(
            path: $relativePath,
            stableKey: $stableKey,
            title: $title,
            summary: $summary,
            type: $type,
            area: $area,
            visibility: $visibility,
            adapter: $this->name(),
            citation: new CitationData(
                source: $request->source,
                path: $relativePath,
                section: null,
                commit: $request->commit,
            ),
            resolutionKey: $this->stringField($frontmatter, 'resolution_key'),
            decisionDate: $this->stringField($frontmatter, 'decision_date'),
            effectiveDate: $this->stringField($frontmatter, 'effective_date'),
            reviewDate: $this->stringField($frontmatter, 'review_date'),
            tags: $tags,
            frontmatter: $frontmatter,
            sections: $structure['sections'],
            tables: [],
            edges: $structure['edges'],
            body: $body,
        );

        return [
            'items' => [$item],
            'skipped' => [],
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    protected function visibility(array $frontmatter, ?ManifestData $manifest): VisibilityEnum
    {
        $fromFrontmatter = VisibilityEnum::tryFromLoose($frontmatter['visibility'] ?? null);
        if ($fromFrontmatter instanceof VisibilityEnum) {
            return $fromFrontmatter;
        }

        return $manifest !== null ? $manifest->defaultVisibility : VisibilityEnum::Internal;
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    protected function stringField(array $frontmatter, string $key): ?string
    {
        $value = $frontmatter[$key] ?? null;

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function firstHeading(string $body): ?string
    {
        if (preg_match('/^#\s+(.+)$/m', $body, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    protected function defaultStableKey(string $relativePath): string
    {
        $withoutExt = preg_replace('/\.md$/i', '', $relativePath) ?? $relativePath;

        return str_replace('\\', '/', $withoutExt);
    }

    protected function areaFromPath(string $relativePath): string
    {
        $parts = explode('/', str_replace('\\', '/', $relativePath));
        $first = strtolower($parts[0]);

        return match ($first) {
            'rules', 'decisions', 'style', 'brand', 'glossary', 'plans', 'notes' => $first === 'brand' ? 'style' : $first,
            'design-system', 'design_system', 'designsystem' => 'design-system',
            'connected-systems', 'connected_systems' => 'connected-systems',
            default => 'notes',
        };
    }
}
