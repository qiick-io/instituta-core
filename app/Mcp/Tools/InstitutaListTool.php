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

#[Name('instituta_list')]
#[Description('List brain items (ids, titles, summaries). No bodies.')]
#[IsReadOnly]
class InstitutaListTool extends Tool
{
    public function __construct(private readonly LocalBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'area' => $schema->string()->description('Optional area filter')->nullable(),
            'type' => $schema->string()->description('Optional type filter')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $repo = $this->brain->repository();
        $items = $this->brain->reader()->list(
            $repo,
            $request->get('area'),
            $request->get('type'),
        );

        return Response::json(['items' => $items]);
    }
}
