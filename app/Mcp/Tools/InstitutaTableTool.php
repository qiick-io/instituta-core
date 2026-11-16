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
 * Local twin of the HTTP instituta_table (#202): the rows of one structured table.
 */
#[Name('instituta_table')]
#[Description('Get the rows of one structured table of an item (for example a component registry), by table key.')]
#[IsReadOnly]
class InstitutaTableTool extends Tool
{
    public function __construct(private readonly LocalBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Item path or stable_key')->required(),
            'table_key' => $schema->string()->description('Table key, listed in instituta_get under tables')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $table = $this->brain->reader()->table(
            $this->brain->repository(),
            (string) $request->get('id'),
            (string) $request->get('table_key'),
        );

        if ($table === null) {
            return Response::error('Table not found.');
        }

        return Response::json(['table' => $table]);
    }
}
