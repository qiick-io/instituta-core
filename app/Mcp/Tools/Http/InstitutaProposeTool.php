<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Actions\ProposeBrainChangeAction;
use App\Data\ProposeBrainChangeData;
use App\Enums\ContractErrorCodeEnum;
use App\Enums\ProposalActionEnum;
use App\Exceptions\ContractException;
use App\Mcp\Support\HttpBrainContext;
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

#[Name('instituta_propose')]
#[Description('Open a brain write proposal (never a direct write). Requires brain:write and mcp.write_enabled. Updates and deletes send the version you read (base_content_hash, or section_anchor + base_section_hash for a one-section edit); a stale base is refused.')]
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
            'body' => $schema->string()->description('Proposed markdown: the whole file, or with section_anchor the new markdown of that section, heading line included (not audited)')->nullable(),
            'source_id' => $schema->string()->description('Optional source ULID')->nullable(),
            'base_content_hash' => $schema->string()->description('origin.content_hash of the item you read; required for a whole-file update or delete')->nullable(),
            'section_anchor' => $schema->string()->description('Edit only this section (update only); body is its new markdown')->nullable(),
            'frontmatter' => $schema->object()->description('Update only: frontmatter keys to set, null removes one (status, superseded_by, review_date, title, visibility, tags, …). Alone or with a body / section edit.')->nullable(),
            'base_section_hash' => $schema->string()->description('section_hash of the section you read; required with section_anchor unless base_content_hash is sent')->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $refusal = $this->writeGate->refusal($this->brain->apiKey(), 'mcp');
        if ($refusal !== null) {
            return Response::error($refusal->errorCode->mcpText($refusal->getMessage()));
        }

        $action = (string) $request->get('action');
        if (ProposalActionEnum::tryFrom($action) === null) {
            return Response::error(ContractErrorCodeEnum::ValidationFailed->mcpText('action must be create, update, or delete.'));
        }

        try {
            $proposal = $this->propose->execute(
                $this->brain->perimeter(),
                $this->brain->apiKey(),
                ProposeBrainChangeData::fromInput($request->all()),
                'mcp',
            );
        } catch (InvalidArgumentException $exception) {
            return Response::error(ContractException::codeOf($exception)->mcpText($exception->getMessage()));
        }

        return Response::structured($this->queries->toProposedSummary($proposal));
    }
}
