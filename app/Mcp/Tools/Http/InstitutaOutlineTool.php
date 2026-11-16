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

#[Name('instituta_outline')]
#[Description('The section structure of one item (anchors, headings, levels, block types), no bodies. Use it to pick the section to open with instituta_get_section.')]
#[IsReadOnly]
class InstitutaOutlineTool extends Tool
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
        $outline = $this->brain->contract()->outline(
            $this->brain->perimeter(),
            $this->brain->apiKey(),
            (string) $request->get('id'),
        );

        if ($outline === null) {
            return Response::structured(['outline' => null]);
        }

        return Response::structured(['outline' => $outline]);
    }
}
