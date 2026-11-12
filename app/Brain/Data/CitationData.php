<?php

declare(strict_types=1);

namespace App\Brain\Data;

final readonly class CitationData
{
    public function __construct(
        public string $source,
        public string $path,
        public ?string $section = null,
        public ?string $commit = null,
    ) {}

    /**
     * @return array{source: string, path: string, section: string|null, commit: string|null}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'path' => $this->path,
            'section' => $this->section,
            'commit' => $this->commit,
        ];
    }
}
