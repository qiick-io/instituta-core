<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Enums\ContractErrorCodeEnum;
use App\Mcp\Concerns\ReadsRequestedSectionsTrait;
use App\Mcp\Support\HttpBrainContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Several sections in one call (#225), for an agent that already knows what it needs.
 */
#[Name('instituta_get_sections')]
#[Description('Get up to 20 sections in one call, each as instituta_get_section returns it (markdown, section_hash, content_hash, citation). Pass the item and anchor of each, from instituta_search hits or instituta_outline. A missing one answers error "not_found" on its own entry; the others still come back.')]
#[IsReadOnly]
class InstitutaGetSectionsTool extends Tool
{
    use ReadsRequestedSectionsTrait;

    public function __construct(private readonly HttpBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return $this->requestedSectionsSchema($schema);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $requestedSections = $this->requestedSections($request);

        if ($requestedSections === null) {
            return Response::error(ContractErrorCodeEnum::ValidationFailed->mcpText(self::INVALID_SECTIONS_MESSAGE));
        }

        return Response::structured($this->brain->contract()->getSections(
            $this->brain->perimeter(),
            $this->brain->apiKey(),
            $requestedSections,
        ));
    }
}
