<?php

declare(strict_types=1);

namespace App\Brain\Data;

use App\Brain\Enums\VisibilityEnum;
use App\Brain\Support\TokenEstimator;

final readonly class ItemData
{
    /**
     * @param  array<string, mixed>  $frontmatter
     * @param  list<string>  $tags
     * @param  list<SectionData>  $sections
     * @param  list<StructuredTableData>  $tables
     * @param  list<LinkEdgeData>  $edges
     */
    public function __construct(
        public string $path,
        public string $stableKey,
        public string $title,
        public string $summary,
        public string $type,
        public string $area,
        public VisibilityEnum $visibility,
        public string $adapter,
        public CitationData $citation,
        public ?string $resolutionKey = null,
        public ?string $decisionDate = null,
        public ?string $effectiveDate = null,
        public ?string $reviewDate = null,
        public array $tags = [],
        public array $frontmatter = [],
        public array $sections = [],
        public array $tables = [],
        public array $edges = [],
        public string $body = '',
    ) {}

    /**
     * What reading the whole item costs (#230): the sum of its sections, so outline, section and
     * item agree; the body when the file has no sections.
     */
    public function tokenEstimate(): int
    {
        if ($this->sections === []) {
            return TokenEstimator::forText($this->body);
        }

        return array_sum(array_map(static fn (SectionData $section): int => $section->tokenEstimate(), $this->sections));
    }
}
