<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The section structure of one item, so the assistant reads only the parts it needs (#203).
 */
final class OutlineBrainItemTool implements Tool
{
    use BrainToolTrait;

    public function description(): string
    {
        return 'The sections (anchors, headings) of one brain item, no bodies. Use it to pick the section to read with ReadBrainSectionTool.';
    }

    public function handle(Request $request): string
    {
        return $this->json($this->contract->outline($this->perimeter, null, (string) ($request['item'] ?? '')));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'item' => $schema->string()->description('Item id, stable_key or path from a search result')->required(),
        ];
    }
}
