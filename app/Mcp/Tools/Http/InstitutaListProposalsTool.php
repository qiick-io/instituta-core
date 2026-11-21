<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Enums\ContractErrorCodeEnum;
use App\Enums\ProposalStatusEnum;
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

#[Name('instituta_list_proposals')]
#[Description('List write proposals in your perimeter (status, action, item, section, base version), no bodies. Needs brain:write.')]
class InstitutaListProposalsTool extends Tool
{
    public function __construct(
        private readonly HttpBrainContext $brain,
        private readonly BrainWriteGateService $writeGate = new BrainWriteGateService,
        private readonly ProposalQueryService $queries = new ProposalQueryService,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Optional filter: open|accepted|rejected')->nullable(),
            'cursor' => $schema->string()->description('Pagination cursor from a previous response')->nullable(),
            'limit' => $schema->integer()->description('Maximum proposals to return (default 25, max 100)')->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $refusal = $this->writeGate->refusal($this->brain->apiKey(), 'mcp');
        if ($refusal !== null) {
            return Response::error($refusal->errorCode->mcpText($refusal->getMessage()));
        }

        $statusValue = $request->get('status');
        $status = is_string($statusValue) && $statusValue !== ''
            ? ProposalStatusEnum::tryFrom($statusValue)
            : null;

        if (is_string($statusValue) && $statusValue !== '' && $status === null) {
            return Response::error(ContractErrorCodeEnum::ValidationFailed->mcpText('status must be open, accepted, or rejected.'));
        }

        $cursor = $request->get('cursor');
        $limit = $request->get('limit');

        return Response::structured(
            $this->queries->list(
                $this->brain->perimeter(),
                $this->brain->apiKey(),
                $status,
                'mcp',
                is_string($cursor) && $cursor !== '' ? $cursor : null,
                is_numeric($limit) ? max(1, min(100, (int) $limit)) : 25,
            ),
        );
    }
}
