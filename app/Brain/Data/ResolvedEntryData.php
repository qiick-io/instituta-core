<?php

declare(strict_types=1);

namespace App\Brain\Data;

use App\Brain\Enums\VisibilityEnum;

final readonly class ResolvedEntryData
{
    public function __construct(
        public string $resolutionKey,
        public string $title,
        public string $summary,
        public VisibilityEnum $visibility,
        public OriginData $origin,
        public bool $fromSharedSource = false,
        public ?string $area = null,
    ) {}

    /**
     * @return array{
     *     resolution_key: string,
     *     title: string,
     *     summary: string,
     *     visibility: string,
     *     origin: array<string, mixed>,
     *     from_shared_source: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'resolution_key' => $this->resolutionKey,
            'title' => $this->title,
            'summary' => $this->summary,
            'visibility' => $this->visibility->value,
            'origin' => $this->origin->toArray(),
            'from_shared_source' => $this->fromSharedSource,
        ];
    }
}
