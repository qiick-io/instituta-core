<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Mcp\Support\HttpBrainContext;
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
#[Description('Full-text search of the brain in your perimeter. Each hit is a matching section (anchor, heading, snippet, citation), at most two per item: open it with instituta_get_section. No full bodies. For questions phrased differently from the brain\'s wording, try instituta_similar.')]
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
