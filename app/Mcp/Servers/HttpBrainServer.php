<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\Http\InstitutaGetSectionTool;
use App\Mcp\Tools\Http\InstitutaGetTool;
use App\Mcp\Tools\Http\InstitutaGraphTool;
use App\Mcp\Tools\Http\InstitutaListTool;
use App\Mcp\Tools\Http\InstitutaOutlineTool;
use App\Mcp\Tools\Http\InstitutaResolveTool;
use App\Mcp\Tools\Http\InstitutaScopesTool;
use App\Mcp\Tools\Http\InstitutaSearchTool;
use App\Mcp\Tools\Http\InstitutaTableTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Instituta Brain')]
#[Version('0.1.0')]
#[Instructions('Read-only Instituta brain over HTTP. Authenticate with Authorization: Bearer <api_key>. MCP is off unless instituta.mcp.enabled is true.')]
class HttpBrainServer extends Server
{
    protected array $tools = [
        InstitutaScopesTool::class,
        InstitutaListTool::class,
        InstitutaSearchTool::class,
        InstitutaGetTool::class,
        InstitutaGetSectionTool::class,
        InstitutaTableTool::class,
        InstitutaOutlineTool::class,
        InstitutaResolveTool::class,
        InstitutaGraphTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
