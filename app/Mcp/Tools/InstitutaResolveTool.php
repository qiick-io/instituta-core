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

#[Name('instituta_resolve')]
#[Description('Resolved resolution_key entries for the local folder (single-owner perimeter).')]
#[IsReadOnly]
class InstitutaResolveTool extends Tool
{
    public function __construct(private readonly LocalBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'area' => $schema->string()->description('Optional area filter')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $entries = $this->brain->reader()->resolve(
            $this->brain->repository(),
            $request->get('area'),
        );

        return Response::json(['entries' => $entries]);
    }
}
