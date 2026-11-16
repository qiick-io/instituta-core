<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Mcp\Support\HttpBrainContext;
use App\Services\HybridSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;
use Throwable;

#[Name('instituta_search')]
#[Description('Full-text search of the brain in your perimeter. Each hit is a matching section (anchor, heading, snippet, citation), at most two per item: open it with instituta_get_section. No full bodies. By default it matches any of the words and, when embeddings are on, also by meaning (hybrid); the answer\'s mode says which ran.')]
#[IsReadOnly]
class InstitutaSearchTool extends Tool
{
    public function __construct(private readonly HttpBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Search query')->required(),
            'area' => $schema->string()->description('Optional area filter')->nullable(),
            'limit' => $schema->integer()->description('Max hits (default 20, max 50)')->nullable(),
            'mode' => $schema->string()->description('hybrid (default: words and meaning when embeddings are on), keyword or semantic')->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $limit = $request->get('limit');

        try {
            return Response::structured(
                $this->brain->contract()->search(
                    $this->brain->perimeter(),
                    $this->brain->apiKey(),
                    (string) $request->get('q'),
                    $request->get('area'),
                    is_numeric($limit) ? (int) $limit : 20,
                    in_array($request->get('mode'), [HybridSearchService::MODE_KEYWORD, HybridSearchService::MODE_SEMANTIC], true)
                        ? (string) $request->get('mode')
                        : HybridSearchService::MODE_HYBRID,
                ),
            );
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return Response::error('search driver not supported');
        }
    }
}
