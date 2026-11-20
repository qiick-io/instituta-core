<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The entries that apply to one product (product over company over organisation) (#203).
 */
final class ResolveProductTool implements Tool
{
    use BrainToolTrait;

    public function description(): string
    {
        return 'The rules, decisions and design entries that apply to one product: for each key the most specific version (product over company over organisation). Use it for questions about a specific product.';
    }

    public function handle(Request $request): string
    {
        $area = $request['area'] ?? null;

        return $this->json($this->contract->resolve(
            $this->perimeter,
            null,
            (string) ($request['product_id'] ?? ''),
            is_string($area) && $area !== '' ? $area : null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->string()->description('Product UUID')->required(),
            'area' => $schema->string()->description('Optional area filter'),
        ];
    }
}
