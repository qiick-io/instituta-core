<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Tools\Http\InstitutaChangesTool;
use App\Mcp\Tools\Http\InstitutaListProposalsTool;
use App\Mcp\Tools\Http\InstitutaListTool;
use App\Models\ApiKey;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Services\PerimeterResolverService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Cursor UUID validation regression is PostgreSQL-specific (22P02).');
    }
});

/**
 * @return array{headers: array<string, string>, key: ApiKey, organisation: Organisation}
 */
function cursorValidationSeed(array $scopes = [ApiKeyScopeEnum::BrainRead->value]): array
{
    if (in_array(ApiKeyScopeEnum::BrainWrite->value, $scopes, true)) {
        config(['instituta.api.write_enabled' => true, 'instituta.mcp.write_enabled' => true]);
    }

    $organisation = Organisation::factory()->create();
    $principal = Principal::factory()->create(['organisation_id' => $organisation->id]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisation->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);
    $issued = app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: 'cursor-validation',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
        scopes: $scopes,
    );

    return [
        'headers' => ['Authorization' => 'Bearer '.$issued['plain']],
        'key' => $issued['key'],
        'organisation' => $organisation,
    ];
}

it('rejects a tampered cursor on the proposals API endpoint', function (): void {
    $seed = cursorValidationSeed([ApiKeyScopeEnum::BrainRead->value, ApiKeyScopeEnum::BrainWrite->value]);

    $this->getJson('/api/v1/proposals?cursor=not-a-uuid', $seed['headers'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor']);
});

it('rejects a tampered cursor on the items API endpoint', function (): void {
    $seed = cursorValidationSeed();

    $this->getJson('/api/v1/items?scope='.$seed['organisation']->id.'&cursor=not-a-uuid', $seed['headers'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor']);
});

it('rejects a tampered cursor on the changes API endpoint', function (): void {
    $seed = cursorValidationSeed();

    $this->getJson('/api/v1/changes?scope='.$seed['organisation']->id.'&since=2026-01-01&cursor=not-a-uuid', $seed['headers'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor']);
});

it('rejects a tampered cursor on the MCP list proposals tool', function (): void {
    config(['instituta.mcp.enabled' => true]);
    $seed = cursorValidationSeed([ApiKeyScopeEnum::BrainRead->value, ApiKeyScopeEnum::BrainWrite->value]);

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['key']->fresh('principal'));
    request()->attributes->set('instituta.api_key', $seed['key']->fresh('principal'));
    request()->attributes->set('instituta.perimeter', $perimeter);

    HttpBrainServer::tool(InstitutaListProposalsTool::class, ['cursor' => 'not-a-uuid'])
        ->assertHasErrors(['cursor must be a valid UUID.']);
});

it('rejects a tampered cursor on the MCP list tool', function (): void {
    config(['instituta.mcp.enabled' => true]);
    $seed = cursorValidationSeed();

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['key']->fresh('principal'));
    request()->attributes->set('instituta.api_key', $seed['key']->fresh('principal'));
    request()->attributes->set('instituta.perimeter', $perimeter);

    HttpBrainServer::tool(InstitutaListTool::class, ['cursor' => 'not-a-uuid'])
        ->assertHasErrors(['cursor must be a valid UUID.']);
});

it('rejects a tampered cursor on the MCP changes tool', function (): void {
    config(['instituta.mcp.enabled' => true]);
    $seed = cursorValidationSeed();

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($seed['key']->fresh('principal'));
    request()->attributes->set('instituta.api_key', $seed['key']->fresh('principal'));
    request()->attributes->set('instituta.perimeter', $perimeter);

    HttpBrainServer::tool(InstitutaChangesTool::class, ['since' => '2026-01-01', 'cursor' => 'not-a-uuid'])
        ->assertHasErrors(['cursor must be a valid UUID.']);
});
