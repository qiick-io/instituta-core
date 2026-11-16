<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Enums\ContractErrorCodeEnum;
use App\Mcp\Support\HttpBrainContext;
use App\Services\BrainEmbeddingService;
use App\Services\ContractAuditService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Semantic neighbours of a question (#202): the same perimeter-scoped embedding search as
 * `GET /api/v1/similar`, for questions whose words do not match the brain's wording.
 */
#[Name('instituta_similar')]
#[Description('Find sections by meaning, not exact words (embeddings). Returns item ids, section anchors, headings and scores, no bodies: open a hit with instituta_get_section. Errors when embeddings are off on this install; use instituta_search then.')]
#[IsReadOnly]
class InstitutaSimilarTool extends Tool
{
    private const int DEFAULT_LIMIT = 10;

    private const int MAX_LIMIT = 50;

    public function __construct(
        private readonly HttpBrainContext $brain,
        private readonly BrainEmbeddingService $embeddings,
        private readonly ContractAuditService $audit = new ContractAuditService,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('The question or topic, in natural language')->required(),
            'limit' => $schema->integer()->description('Maximum hits (1–50, default 10)')->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (! config('ai.embeddings.enabled')) {
            return Response::error(ContractErrorCodeEnum::EmbeddingsDisabled->mcpText('Embeddings are disabled on this install (AI_EMBEDDINGS_ENABLED=false); use instituta_search.'));
        }

        $query = trim((string) $request->get('q'));
        if ($query === '') {
            return Response::error(ContractErrorCodeEnum::ValidationFailed->mcpText('q is required.'));
        }

        $limit = max(1, min(self::MAX_LIMIT, (int) ($request->get('limit') ?? self::DEFAULT_LIMIT)));
        $perimeter = $this->brain->perimeter();
        $results = $this->embeddings->similar($perimeter, $query, $limit);

        $this->audit->record(
            'instituta_similar',
            $perimeter,
            $this->brain->apiKey(),
            array_map(static fn (array $row): string => $row['section_id'], $results),
        );

        return Response::structured(['query' => $query, 'results' => $results]);
    }
}
