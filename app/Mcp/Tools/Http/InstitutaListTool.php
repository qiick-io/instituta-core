<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Enums\ContractErrorCodeEnum;
use App\Mcp\Support\HttpBrainContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('instituta_list')]
#[Description('List brain items in your perimeter (ids, titles, summaries, areas), optionally by area or type. No bodies: open an item with instituta_outline then instituta_get_section.')]
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
        $cursor = $request->get('cursor');
        if (is_string($cursor) && trim($cursor) === '') {
            $cursor = null;
        }
        if ($cursor !== null && (! is_string($cursor) || ! Str::isUuid($cursor))) {
            return Response::error(ContractErrorCodeEnum::ValidationFailed->mcpText('cursor must be a valid UUID.'));
        }

        $limit = $request->get('limit');

        return Response::structured(
            $this->brain->contract()->list(
                $this->brain->perimeter(),
                $this->brain->apiKey(),
                $request->get('area'),
                $request->get('type'),
                is_string($cursor) ? $cursor : null,
                is_numeric($limit) ? (int) $limit : 25,
            ),
        );
    }
}
