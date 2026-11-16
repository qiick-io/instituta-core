<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\BrainEmbeddingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Semantic search for the assistant (#203); only offered when embeddings are on.
 */
final class SimilarBrainSectionsTool implements Tool
{
    use BrainToolTrait;

    public function description(): string
    {
        return 'Find brain sections by meaning when the words of the question differ from the brain\'s wording. Returns item ids, section anchors, headings and scores; read a hit with ReadBrainSectionTool.';
    }

    public function handle(Request $request): string
    {
        return $this->json([
            'results' => app(BrainEmbeddingService::class)->similar($this->perimeter, (string) ($request['q'] ?? ''), 8),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('The question or topic in natural language')->required(),
        ];
    }
}
