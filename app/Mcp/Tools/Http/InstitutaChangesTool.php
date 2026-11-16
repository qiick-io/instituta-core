<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Enums\ContractErrorCodeEnum;
use App\Exceptions\ContractException;
use App\Mcp\Support\HttpBrainContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The change feed (#228) for agents that keep a memory of the brain.
 */
#[Name('instituta_changes')]
#[Description('What changed in the brain since a date-time (ISO 8601) or a vault version label (instituta@vN): items created, updated (with changed_sections, the anchors whose content changed) and removed, oldest first, each with status, superseded_by and citation. Use it to refresh what you remember instead of re-reading everything; follow next_cursor for more.')]
#[IsReadOnly]
class InstitutaChangesTool extends Tool
{
    public function __construct(private readonly HttpBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'since' => $schema->string()->description('ISO 8601 date-time, or a vault version label such as instituta@v12')->required(),
            'area' => $schema->string()->description('Optional area filter')->nullable(),
            'cursor' => $schema->string()->description('next_cursor from the previous page')->nullable(),
            'limit' => $schema->integer()->description('Max changes (default 50, max 200)')->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $since = $request->get('since');

        if (! is_string($since) || trim($since) === '') {
            return Response::error(ContractErrorCodeEnum::ValidationFailed->mcpText('since is required.'));
        }

        $area = $request->get('area');
        $cursor = $request->get('cursor');
        $limit = $request->get('limit');

        try {
            return Response::structured($this->brain->contract()->changes(
                $this->brain->perimeter(),
                $this->brain->apiKey(),
                $since,
                is_string($area) && $area !== '' ? $area : null,
                is_string($cursor) && $cursor !== '' ? $cursor : null,
                is_numeric($limit) ? (int) $limit : 50,
            ));
        } catch (ContractException $exception) {
            return Response::error($exception->errorCode->mcpText($exception->getMessage()));
        }
    }
}
