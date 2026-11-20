<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use App\Mcp\Support\HttpBrainContext;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/**
 * "Load the context for product X" (#202): the resolved view of the product (product over
 * company over organisation) plus how to dig deeper, so an agent starts work with the rules
 * that apply instead of searching blind.
 */
#[Name('instituta_product_context')]
#[Description('Load the brain context for one product: the resolved rules, decisions and design entries that apply to it, with how to open any of them.')]
class InstitutaProductContextPrompt extends Prompt
{
    public function __construct(private readonly HttpBrainContext $brain = new HttpBrainContext) {}

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('product_id', 'Product UUID (see instituta_scopes)', required: true),
            new Argument('area', 'Optional area to narrow the context (rules, decisions, design-system, …)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $productId = (string) $request->get('product_id');
        $area = $request->get('area');

        $resolved = $this->brain->contract()->resolve(
            $this->brain->perimeter(),
            $this->brain->apiKey(),
            $productId,
            is_string($area) && $area !== '' ? $area : null,
        );

        $json = json_encode($resolved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        return Response::text(<<<TEXT
            You are working on product {$productId}. Below is the resolved brain view for it: each entry is the most specific version that applies (product over company over organisation).

            How to use it:
            - Treat the entries as the rules to follow; cite their path when you rely on one.
            - Open an entry with instituta_outline, then instituta_get_section for the part you need, instead of loading whole items.
            - To change the brain, read the item first and use instituta_propose with its base version; a person accepts the change.

            Resolved view:
            {$json}
            TEXT);
    }
}
