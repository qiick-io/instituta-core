<?php

declare(strict_types=1);

namespace App\Brain\Data;

final readonly class LinkEdgeData
{
    public function __construct(
        public string $fromPath,
        public string $target,
        public string $relation = 'links_to',
        public ?string $fromSection = null,
    ) {}

    /**
     * @return array{
     *     from_path: string,
     *     target: string,
     *     relation: string,
     *     from_section: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'from_path' => $this->fromPath,
            'target' => $this->target,
            'relation' => $this->relation,
            'from_section' => $this->fromSection,
        ];
    }
}
