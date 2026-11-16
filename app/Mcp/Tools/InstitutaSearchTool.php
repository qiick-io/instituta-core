<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\LocalBrainContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('instituta_search')]
#[Description('Search brain items in the local folder: summaries and snippets, no full bodies.')]
#[IsReadOnly]
class InstitutaSearchTool extends Tool
{
    public function __construct(private readonly LocalBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Search query')->required(),
            'area' => $schema->string()->description('Optional area filter')->nullable(),
            'limit' => $schema->integer()->description('Max results (default 20, max 50)')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $repo = $this->brain->repository();
        $items = $this->brain->reader()->search(
            $repo,
            (string) $request->get('q', ''),
            $request->get('area'),
            (int) ($request->get('limit') ?? 20),
        );

        return Response::json(['items' => $items]);
    }
}
