<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\InstitutaGetSectionsTool;
use App\Mcp\Tools\InstitutaGetSectionTool;
use App\Mcp\Tools\InstitutaGetTool;
use App\Mcp\Tools\InstitutaGraphTool;
use App\Mcp\Tools\InstitutaListTool;
use App\Mcp\Tools\InstitutaOutlineTool;
use App\Mcp\Tools\InstitutaResolveTool;
use App\Mcp\Tools\InstitutaScopesTool;
use App\Mcp\Tools\InstitutaSearchTool;
use App\Mcp\Tools\InstitutaTableTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Instituta Local Brain')]
#[Version('0.1.0')]
#[Instructions('Read-only Instituta brain over a local folder (php artisan instituta:mcp --path=<repo>). Read progressively: instituta_list or instituta_search (find, no bodies), instituta_outline (structure), instituta_get_section (one part; instituta_get_sections for several), instituta_table (structured rows); instituta_resolve gives the resolved entries. Cite the path of what you rely on. Writes are not available locally: use the HTTP server to propose changes.')]
class LocalBrainServer extends Server
{
    protected array $tools = [
        InstitutaScopesTool::class,
        InstitutaListTool::class,
        InstitutaSearchTool::class,
        InstitutaGetTool::class,
        InstitutaGetSectionTool::class,
        InstitutaGetSectionsTool::class,
        InstitutaTableTool::class,
        InstitutaOutlineTool::class,
        InstitutaResolveTool::class,
        InstitutaGraphTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
