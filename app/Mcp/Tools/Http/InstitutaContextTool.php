<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Enums\ContractErrorCodeEnum;
use App\Mcp\Support\HttpBrainContext;
use App\Services\IndexedContractService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

/**
 * The context pack (#224): the first call for a task, replacing a round of search, outline and
 * section reads.
 */
#[Name('instituta_context')]
#[Description('Start here for a task: one call returns the context to work from, within a token budget. With product_id, the product\'s resolved entries first (one line each); then the most relevant sections already read (markdown, anchor, status, citation, token_estimate), active entries first. omitted lists what did not fit: fetch it with instituta_get_sections.')]
#[IsReadOnly]
class InstitutaContextTool extends Tool
{
    public function __construct(private readonly HttpBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('The task or question, in words')->required(),
            'product_id' => $schema->string()->description('Optional product, to put its resolved entries first')->nullable(),
            'area' => $schema->string()->description('Optional area filter')->nullable(),
            'budget' => $schema->integer()->description('Token budget (default 4000, 500 to 16000)')->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $q = $request->get('q');

        if (! is_string($q) || trim($q) === '') {
            return Response::error(ContractErrorCodeEnum::ValidationFailed->mcpText('q is required.'));
        }

        $budget = $request->get('budget');
        $productId = $request->get('product_id');
        $area = $request->get('area');

        try {
            return Response::structured($this->brain->contract()->context(
                $this->brain->perimeter(),
                $this->brain->apiKey(),
                $q,
                is_string($productId) && $productId !== '' ? $productId : null,
                is_string($area) && $area !== '' ? $area : null,
                is_numeric($budget) ? (int) $budget : IndexedContractService::CONTEXT_DEFAULT_BUDGET,
            ));
        } catch (RuntimeException $exception) {
            return Response::error(ContractErrorCodeEnum::SearchUnsupported->mcpText($exception->getMessage()));
        }
    }
}
