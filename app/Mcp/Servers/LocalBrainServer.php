<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\InstitutaGetTool;
use App\Mcp\Tools\InstitutaGraphTool;
use App\Mcp\Tools\InstitutaListTool;
use App\Mcp\Tools\InstitutaOutlineTool;
use App\Mcp\Tools\InstitutaResolveTool;
use App\Mcp\Tools\InstitutaScopesTool;
use App\Mcp\Tools\InstitutaSearchTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Instituta Local Brain')]
#[Version('0.1.0')]
#[Instructions('Read-only Instituta brain tools over a local folder. Start with php artisan instituta:mcp --path=<repo>.')]
class LocalBrainServer extends Server
{
    protected array $tools = [
        InstitutaScopesTool::class,
        InstitutaListTool::class,
        InstitutaSearchTool::class,
        InstitutaGetTool::class,
        InstitutaOutlineTool::class,
        InstitutaResolveTool::class,
        InstitutaGraphTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
