<?php

declare(strict_types=1);

namespace App\Brain\Data;

use App\Brain\Enums\VisibilityEnum;

final readonly class ManifestData
{
    /**
     * @param  list<string>  $areas
     */
    public function __construct(
        public string $format,
        public int $formatVersion,
        public ?string $name = null,
        public VisibilityEnum $defaultVisibility = VisibilityEnum::Internal,
        public array $areas = [],
    ) {}
}
