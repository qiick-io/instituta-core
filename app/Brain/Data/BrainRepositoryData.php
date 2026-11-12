<?php

declare(strict_types=1);

namespace App\Brain\Data;

final readonly class BrainRepositoryData
{
    /**
     * @param  list<ItemData>  $items
     * @param  list<LinkEdgeData>  $edges
     * @param  list<SkippedFileData>  $skipped
     * @param  list<ValidationIssueData>  $issues
     */
    public function __construct(
        public string $path,
        public string $source,
        public ?string $commit,
        public ?ManifestData $manifest,
        public array $items,
        public array $edges,
        public array $skipped,
        public array $issues,
        public bool $denied = false,
    ) {}
}
