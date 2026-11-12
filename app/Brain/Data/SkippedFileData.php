<?php

declare(strict_types=1);

namespace App\Brain\Data;

final readonly class SkippedFileData
{
    public function __construct(
        public string $path,
        public string $reason,
    ) {}

    /**
     * @return array{path: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'reason' => $this->reason,
        ];
    }
}
