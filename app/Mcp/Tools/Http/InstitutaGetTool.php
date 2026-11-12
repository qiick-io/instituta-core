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

#[Name('instituta_get')]
#[Description('Get one brain item by id, stable_key, or path.')]
#[IsReadOnly]
class InstitutaGetTool extends Tool
{
    public function __construct(private readonly HttpBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Item id, stable_key, or path')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $item = $this->brain->contract()->get(
            $this->brain->perimeter(),
            $this->brain->apiKey(),
            (string) $request->get('id'),
        );

        if ($item === null) {
            return Response::structured(['item' => null]);
        }

        return Response::structured(['item' => $item]);
    }
}
