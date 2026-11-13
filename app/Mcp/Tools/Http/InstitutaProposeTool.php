<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Actions\ProposeBrainChangeAction;
use App\Data\ProposeBrainChangeData;
use App\Enums\ProposalActionEnum;
use App\Mcp\Support\HttpBrainContext;
use App\Services\BrainWriteGateService;
use App\Services\ProposalQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('instituta_propose')]
#[Description('Open a brain write proposal (never a direct write). Requires brain:write and mcp.write_enabled.')]
class InstitutaProposeTool extends Tool
{
    public function __construct(
        private readonly HttpBrainContext $brain,
        private readonly BrainWriteGateService $writeGate = new BrainWriteGateService,
        private readonly ProposeBrainChangeAction $propose = new ProposeBrainChangeAction,
        private readonly ProposalQueryService $queries = new ProposalQueryService,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('create|update|delete')->required(),
            'stable_key' => $schema->string()->description('Stable item key')->required(),
            'title' => $schema->string()->description('Proposal title')->required(),
            'path' => $schema->string()->description('Repository-relative path')->required(),
            'area' => $schema->string()->description('Optional area')->nullable(),
            'body' => $schema->string()->description('Proposed markdown body (not audited)')->nullable(),
            'source_id' => $schema->string()->description('Optional source ULID')->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $reason = $this->writeGate->refusalReason($this->brain->apiKey(), 'mcp');
        if ($reason !== null) {
            return Response::error($reason);
        }

        $action = (string) $request->get('action');
        if (ProposalActionEnum::tryFrom($action) === null) {
            return Response::error('action must be create, update, or delete.');
        }

        $proposal = $this->propose->execute(
            $this->brain->perimeter(),
            $this->brain->apiKey(),
            new ProposeBrainChangeData(
                action: ProposalActionEnum::from($action),
                stableKey: (string) $request->get('stable_key'),
                title: (string) $request->get('title'),
                path: (string) $request->get('path'),
                area: $request->get('area'),
                body: $request->get('body'),
                sourceId: $request->get('source_id'),
            ),
            'mcp',
        );

        return Response::structured($this->queries->toSummary($proposal));
    }
}
