<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\ContractErrorCodeEnum;
use App\Mcp\Concerns\ReadsRequestedSectionsTrait;
use App\Mcp\Support\LocalBrainContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Local twin of the HTTP instituta_get_sections (#225).
 */
#[Name('instituta_get_sections')]
#[Description('Get up to 20 sections in one call, each as instituta_get_section returns it. Pass the item (path or stable_key) and anchor of each; a missing one answers error "not_found" on its own entry.')]
#[IsReadOnly]
class InstitutaGetSectionsTool extends Tool
{
    use ReadsRequestedSectionsTrait;

    public function __construct(private readonly LocalBrainContext $brain) {}

    public function schema(JsonSchema $schema): array
    {
        return $this->requestedSectionsSchema($schema);
    }

    public function handle(Request $request): Response
    {
        $requestedSections = $this->requestedSections($request);

        if ($requestedSections === null) {
            return Response::error(ContractErrorCodeEnum::ValidationFailed->mcpText(self::INVALID_SECTIONS_MESSAGE));
        }

        return Response::json($this->brain->reader()->getSections($this->brain->repository(), $requestedSections));
    }
}
