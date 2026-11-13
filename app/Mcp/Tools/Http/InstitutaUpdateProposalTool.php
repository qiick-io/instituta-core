<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Actions\UpdateProposalStatusAction;
use App\Data\UpdateProposalStatusData;
use App\Enums\ProposalStatusEnum;
use App\Mcp\Support\HttpBrainContext;
use App\Models\Proposal;
use App\Services\BrainWriteGateService;
use App\Services\ProposalQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('instituta_update_proposal')]
#[Description('Accept or reject an open brain write proposal in the key perimeter.')]
class InstitutaUpdateProposalTool extends Tool
{
    public function __construct(
        private readonly HttpBrainContext $brain,
        private readonly BrainWriteGateService $writeGate = new BrainWriteGateService,
        private readonly UpdateProposalStatusAction $updateStatus = new UpdateProposalStatusAction,
        private readonly ProposalQueryService $queries = new ProposalQueryService,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Proposal ULID')->required(),
            'status' => $schema->string()->description('accepted|rejected')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $reason = $this->writeGate->refusalReason($this->brain->apiKey(), 'mcp');
        if ($reason !== null) {
            return Response::error($reason);
        }

        $statusValue = (string) $request->get('status');
        $status = ProposalStatusEnum::tryFrom($statusValue);
        if (! in_array($status, [ProposalStatusEnum::Accepted, ProposalStatusEnum::Rejected], true)) {
            return Response::error('status must be accepted or rejected.');
        }

        $perimeter = $this->brain->perimeter();
        $proposal = Proposal::query()
            ->whereKey((string) $request->get('id'))
            ->where('scope_type', $perimeter->scopeType)
            ->where('scope_id', $perimeter->scopeId)
            ->first();

        if ($proposal === null) {
            return Response::error('Proposal not found.');
        }

        try {
            $updated = $this->updateStatus->execute(
                $perimeter,
                $this->brain->apiKey(),
                $proposal,
                new UpdateProposalStatusData(status: $status),
                'mcp',
            );
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured($this->queries->toSummary($updated));
    }
}
