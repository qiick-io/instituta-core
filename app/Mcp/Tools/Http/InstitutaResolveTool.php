<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Mcp\Support\HttpBrainContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('instituta_resolve')]
#[Description('The rules, decisions and design entries that apply to one product: for each key the most specific version (product over company over organisation). Use it before working on a product.')]
#[IsReadOnly]
class InstitutaResolveTool extends Tool
{
    public function __construct(private readonly HttpBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->string()->description('Product ULID')->required(),
            'area' => $schema->string()->description('Optional area filter')->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return Response::structured(
            $this->brain->contract()->resolve(
                $this->brain->perimeter(),
                $this->brain->apiKey(),
                (string) $request->get('product_id'),
                $request->get('area'),
            ),
        );
    }
}
