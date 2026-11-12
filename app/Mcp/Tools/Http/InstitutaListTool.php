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

#[Name('instituta_list')]
#[Description('List brain items inside the key perimeter. No bodies.')]
#[IsReadOnly]
class InstitutaListTool extends Tool
{
    public function __construct(private readonly HttpBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'area' => $schema->string()->description('Optional area filter')->nullable(),
            'type' => $schema->string()->description('Optional type filter')->nullable(),
            'cursor' => $schema->string()->description('Opaque cursor for the next page')->nullable(),
            'limit' => $schema->integer()->description('Page size (default 25, max 100)')->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $limit = $request->get('limit');

        return Response::structured(
            $this->brain->contract()->list(
                $this->brain->perimeter(),
                $this->brain->apiKey(),
                $request->get('area'),
                $request->get('type'),
                $request->get('cursor'),
                is_numeric($limit) ? (int) $limit : 25,
            ),
        );
    }
}
