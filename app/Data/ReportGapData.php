<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\GapReportKindEnum;

/**
 * A gap report as an agent sends it (#229).
 */
final readonly class ReportGapData
{
    /**
     * @param  list<array{item: string, anchor: string|null}>  $related  Items (id, stable_key or path) and optional anchors it concerns.
     */
    public function __construct(
        public GapReportKindEnum $kind,
        public string $summary,
        public ?string $question = null,
        public array $related = [],
    ) {}
}
