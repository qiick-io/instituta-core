<?php

declare(strict_types=1);

namespace App\Brain\Data;

final readonly class SectionData
{
    /**
     * @param  list<BlockData>  $blocks
     */
    public function __construct(
        public string $anchor,
        public string $heading,
        public int $level,
        public int $position,
        public string $body,
        public array $blocks = [],
    ) {}

    /**
     * @return array{
     *     anchor: string,
     *     heading: string,
     *     level: int,
     *     position: int,
     *     body: string,
     *     blocks: list<array{type: string, payload: array<string, mixed>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'anchor' => $this->anchor,
            'heading' => $this->heading,
            'level' => $this->level,
            'position' => $this->position,
            'body' => $this->body,
            'blocks' => array_map(
                static fn (BlockData $block): array => $block->toArray(),
                $this->blocks,
            ),
        ];
    }
}
