<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Full-text search of the brain for the assistant (#203).
 */
final class SearchBrainTool implements Tool
{
    use BrainToolTrait;

    public function description(): string
    {
        return 'Search the company brain by words. Returns item ids, titles, paths, summaries and snippets, never full bodies. Start here, then open the best hits with OutlineBrainItemTool and ReadBrainSectionTool.';
    }

    public function handle(Request $request): string
    {
        $area = $request['area'] ?? null;

        return $this->json($this->contract->search(
            $this->perimeter,
            null,
            (string) ($request['q'] ?? ''),
            is_string($area) && $area !== '' ? $area : null,
            10,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Words to search for')->required(),
            'area' => $schema->string()->description('Optional area: rules, decisions, style, glossary, design-system, plans, connected-systems, notes'),
        ];
    }
}
