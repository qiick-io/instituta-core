<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The assistant's first tool (#224): the context pack for the question in one call.
 */
final class BrainContextTool implements Tool
{
    use BrainToolTrait;

    public function description(): string
    {
        return 'Call this first for every question: it returns the most relevant brain sections already read (markdown, status, citation) within a token budget, and with product_id the product\'s resolved rules first. omitted lists what did not fit; read those with ReadBrainSectionTool only if you need them.';
    }

    public function handle(Request $request): string
    {
        $productId = $request['product_id'] ?? null;
        $area = $request['area'] ?? null;

        return $this->json($this->contract->context(
            $this->perimeter,
            null,
            (string) ($request['q'] ?? ''),
            is_string($productId) && $productId !== '' ? $productId : null,
            is_string($area) && $area !== '' ? $area : null,
            3000,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('The question or task, in words')->required(),
            'product_id' => $schema->string()->description('Optional product id, to put its resolved rules first'),
            'area' => $schema->string()->description('Optional area: rules, decisions, style, glossary, design-system, plans, connected-systems, notes'),
        ];
    }
}
