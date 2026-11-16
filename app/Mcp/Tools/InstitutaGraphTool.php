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

#[Name('instituta_graph')]
#[Description('Items linked to one item in the local folder (derived edges): what it references and what references it.')]
#[IsReadOnly]
class InstitutaGraphTool extends Tool
{
    public function __construct(private readonly LocalBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Item path or stable_key')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $neighbours = $this->brain->reader()->graph(
            $this->brain->repository(),
            (string) $request->get('id'),
        );

        return Response::json(['neighbours' => $neighbours]);
    }
}
