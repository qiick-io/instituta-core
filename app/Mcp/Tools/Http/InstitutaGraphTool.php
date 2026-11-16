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

#[Name('instituta_graph')]
#[Description('Items linked to one item (depth 1–2): what it references and what references it. Use it to find related rules or decisions.')]
#[IsReadOnly]
class InstitutaGraphTool extends Tool
{
    public function __construct(private readonly HttpBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Item id, stable_key, or path')->required(),
            'depth' => $schema->integer()->description('Depth (default 1, max 2)')->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $depth = $request->get('depth');

        return Response::structured(
            $this->brain->contract()->graph(
                $this->brain->perimeter(),
                $this->brain->apiKey(),
                (string) $request->get('id'),
                is_numeric($depth) ? (int) $depth : 1,
            ),
        );
    }
}
