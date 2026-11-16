<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\ContractErrorCodeEnum;
use App\Mcp\Support\LocalBrainContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('instituta_get')]
#[Description('Get one whole item by path or stable_key, with every section. For long items prefer instituta_outline plus instituta_get_section.')]
#[IsReadOnly]
class InstitutaGetTool extends Tool
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
        $item = $this->brain->reader()->get(
            $this->brain->repository(),
            (string) $request->get('id'),
        );

        if ($item === null) {
            return Response::error(ContractErrorCodeEnum::NotFound->mcpText('Item not found.'));
        }

        return Response::json(['item' => $item]);
    }
}
