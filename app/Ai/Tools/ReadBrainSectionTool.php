<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * One section of an item, with its citation, for the assistant to quote (#203).
 */
final class ReadBrainSectionTool implements Tool
{
    use BrainToolTrait;

    public function description(): string
    {
        return 'Read one section of a brain item: its markdown and citation (path, section, commit). Cite the path and section when you use it.';
    }

    public function handle(Request $request): string
    {
        return $this->json($this->contract->getSection(
            $this->perimeter,
            null,
            (string) ($request['item'] ?? ''),
            (string) ($request['anchor'] ?? ''),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'item' => $schema->string()->description('Item id, stable_key or path')->required(),
            'anchor' => $schema->string()->description('Section anchor from OutlineBrainItemTool')->required(),
        ];
    }
}
