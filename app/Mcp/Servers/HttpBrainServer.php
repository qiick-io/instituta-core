<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\InstitutaProductContextPrompt;
use App\Mcp\Prompts\InstitutaProposeChangePrompt;
use App\Mcp\Resources\InstitutaBrainMapResource;
use App\Mcp\Resources\InstitutaResolvedProductResource;
use App\Mcp\Tools\Http\InstitutaGetSectionTool;
use App\Mcp\Tools\Http\InstitutaGetTool;
use App\Mcp\Tools\Http\InstitutaGraphTool;
use App\Mcp\Tools\Http\InstitutaListProposalsTool;
use App\Mcp\Tools\Http\InstitutaListTool;
use App\Mcp\Tools\Http\InstitutaOutlineTool;
use App\Mcp\Tools\Http\InstitutaProposeTool;
use App\Mcp\Tools\Http\InstitutaResolveTool;
use App\Mcp\Tools\Http\InstitutaScopesTool;
use App\Mcp\Tools\Http\InstitutaSearchTool;
use App\Mcp\Tools\Http\InstitutaSimilarTool;
use App\Mcp\Tools\Http\InstitutaTableTool;
use App\Mcp\Tools\Http\InstitutaUpdateProposalTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Instituta Brain')]
#[Version('0.1.0')]
#[Instructions('Instituta is a company brain: brand, rules, decisions, design systems and plans, scoped to your API key. Read progressively: instituta_scopes (what you can see), then instituta_search or instituta_similar (find), instituta_outline (structure), instituta_get_section (only the part you need); instituta_resolve gives the rules that apply to one product. List and search never return bodies. Cite the path of what you rely on. Writes are proposals only (brain:write and the MCP write switch): read the item, send its base version with instituta_propose, and let a person accept it. Prompts instituta_product_context and instituta_propose_change set these steps up for you.')]
class HttpBrainServer extends Server
{
    protected array $tools = [
        InstitutaScopesTool::class,
        InstitutaListTool::class,
        InstitutaSearchTool::class,
        InstitutaSimilarTool::class,
        InstitutaGetTool::class,
        InstitutaGetSectionTool::class,
        InstitutaTableTool::class,
        InstitutaOutlineTool::class,
        InstitutaResolveTool::class,
        InstitutaGraphTool::class,
        InstitutaProposeTool::class,
        InstitutaListProposalsTool::class,
        InstitutaUpdateProposalTool::class,
    ];

    protected array $resources = [
        InstitutaBrainMapResource::class,
        InstitutaResolvedProductResource::class,
    ];

    protected array $prompts = [
        InstitutaProductContextPrompt::class,
        InstitutaProposeChangePrompt::class,
    ];
}
