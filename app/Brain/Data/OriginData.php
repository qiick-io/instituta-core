<?php

declare(strict_types=1);

namespace App\Brain\Data;

use App\Brain\Enums\ScopeTypeEnum;

final readonly class OriginData
{
    public function __construct(
        public ScopeTypeEnum $scopeType,
        public ?string $scopeId,
        public string $source,
        public string $path,
        public ?string $commit = null,
    ) {}

    /**
     * @return array{
     *     scope_type: string,
     *     scope_id: string|null,
     *     source: string,
     *     path: string,
     *     commit: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'scope_type' => $this->scopeType->value,
            'scope_id' => $this->scopeId,
            'source' => $this->source,
            'path' => $this->path,
            'commit' => $this->commit,
        ];
    }
}
