<?php

declare(strict_types=1);

namespace App\Brain\Data;

use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;

/**
 * One resolvable knowledge entry already attributed to a scope layer.
 */
final readonly class ScopeEntryData
{
    public function __construct(
        public string $resolutionKey,
        public string $title,
        public string $summary,
        public VisibilityEnum $visibility,
        public ScopeTypeEnum $scopeType,
        public string $scopeId,
        public string $source,
        public string $path,
        public ?string $commit = null,
        public ?string $area = null,
        public bool $fromSharedSource = false,
        public ?string $sharedSourceId = null,
        public ?string $sharedSourceVersionId = null,
    ) {}
}
