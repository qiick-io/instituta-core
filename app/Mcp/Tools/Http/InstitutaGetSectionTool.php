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

#[Name('instituta_get_section')]
#[Description('Get one section of an item by anchor (from instituta_outline): its blocks, source markdown, section_hash and citation. The cheapest way to read just what you need.')]
#[IsReadOnly]
class InstitutaGetSectionTool extends Tool
{
    public function __construct(private readonly HttpBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Item id, stable_key, or path')->required(),
            'anchor' => $schema->string()->description('Section anchor')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $section = $this->brain->contract()->getSection(
            $this->brain->perimeter(),
            $this->brain->apiKey(),
            (string) $request->get('id'),
            (string) $request->get('anchor'),
        );

        if ($section === null) {
            return Response::structured(['section' => null]);
        }

        return Response::structured(['section' => $section]);
    }
}
