<?php

declare(strict_types=1);

namespace App\Brain\Data;

final readonly class ValidationIssueData
{
    public function __construct(
        public string $path,
        public string $code,
        public string $message,
        public string $severity = 'error',
    ) {}

    /**
     * @return array{path: string, code: string, message: string, severity: string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity,
        ];
    }
}
