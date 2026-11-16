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

#[Name('instituta_scopes')]
#[Description('Start here: the single-owner perimeter of the local folder this server reads.')]
#[IsReadOnly]
class InstitutaScopesTool extends Tool
{
    public function __construct(private readonly LocalBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        unset($request);
        $path = $this->brain->request()->path;

        return Response::json([
            'principal' => [
                'type' => 'person',
                'role' => 'owner',
            ],
            'perimeter' => [
                'scope_type' => 'product',
                'scope_id' => null,
                'role' => 'owner',
                'max_visibility' => 'restricted',
                'path' => $path,
            ],
            'scopes' => [
                [
                    'scope_type' => 'product',
                    'label' => 'local folder',
                    'path' => $path,
                ],
            ],
        ]);
    }
}
