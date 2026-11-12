<?php

declare(strict_types=1);

namespace App\Brain\Data;

use App\Brain\Enums\BlockTypeEnum;

final readonly class BlockData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public BlockTypeEnum $type,
        public array $payload,
    ) {}

    /**
     * @return array{type: string, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'payload' => $this->payload,
        ];
    }
}
