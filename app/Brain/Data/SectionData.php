<?php

declare(strict_types=1);

namespace App\Brain\Data;

final readonly class SectionData
{
    /**
     * @param  list<BlockData>  $blocks
     * @param  string  $markdown  The section's source markdown (heading line included), so a writer can edit it without losing formatting.
     * @param  int  $startLine  First line of the section in the markdown body (1-based, after frontmatter); 0 when unknown.
     * @param  int  $endLine  Last line of the section in the markdown body; 0 when unknown.
     */
    public function __construct(
        public string $anchor,
        public string $heading,
        public int $level,
        public int $position,
        public string $body,
        public array $blocks = [],
        public string $markdown = '',
        public int $startLine = 0,
        public int $endLine = 0,
    ) {}

    /**
     * @return array{
     *     anchor: string,
     *     heading: string,
     *     level: int,
     *     position: int,
     *     body: string,
     *     markdown: string,
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
            'markdown' => $this->markdown,
            'blocks' => array_map(
                static fn (BlockData $block): array => $block->toArray(),
                $this->blocks,
            ),
        ];
    }
}
