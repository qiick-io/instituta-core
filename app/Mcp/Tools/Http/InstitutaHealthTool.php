<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Brain\Enums\RoleEnum;
use App\Enums\ContractErrorCodeEnum;
use App\Mcp\Support\HttpBrainContext;
use App\Services\BrainHealthService;
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
 * Brain health for owner keys (#231), so an agent tending the brain finds what to fix.
 */
#[Name('instituta_health')]
#[Description('Owner keys only: what is wrong in the brain after the last syncs. Each issue has a code (reader_issue, skipped_file, broken_link, missing_superseded_by, duplicate_resolution_key, review_overdue), severity, item, path, message and suggested_fix. Fix them with instituta_propose.')]
#[IsReadOnly]
class InstitutaHealthTool extends Tool
{
    public function __construct(
        private readonly HttpBrainContext $brain,
        private readonly BrainHealthService $health = new BrainHealthService,
        private readonly ContractAuditService $audit = new ContractAuditService,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $perimeter = $this->brain->perimeter();

        if ($perimeter->role !== RoleEnum::Owner) {
            return Response::error(ContractErrorCodeEnum::Forbidden->mcpText('Brain health is for owner keys.'));
        }

        $this->audit->record('instituta_health', $perimeter, $this->brain->apiKey(), []);

        return Response::structured($this->health->check($perimeter));
    }
}
