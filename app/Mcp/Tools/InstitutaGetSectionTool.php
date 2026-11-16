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

/**
 * Local twin of the HTTP instituta_get_section (#202): one section, not the whole file.
 */
#[Name('instituta_get_section')]
#[Description('Get one section of an item by anchor (from instituta_outline): its markdown, blocks and citation. Prefer it to instituta_get for long items.')]
#[IsReadOnly]
class InstitutaGetSectionTool extends Tool
{
    public function __construct(private readonly LocalBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Item path or stable_key')->required(),
            'anchor' => $schema->string()->description('Section anchor from instituta_outline')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $section = $this->brain->reader()->getSection(
            $this->brain->repository(),
            (string) $request->get('id'),
            (string) $request->get('anchor'),
        );

        if ($section === null) {
            return Response::error('Section not found.');
        }

        return Response::json(['section' => $section]);
    }
}
