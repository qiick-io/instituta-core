<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyAbilityEnum;
use App\Enums\ProposalStatusEnum;
use App\Models\ApiKey;
use App\Models\ContractCall;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Proposal;
use App\Services\ApiKeyIssuerService;

/**
 * @return array{org: Organisation, principal: Principal, key: ApiKey, plain: string}
 */
function writeSeedOwnerKey(bool $withWriteAbility = true): array
{
    config([
        'instituta.api.write_enabled' => true,
        'instituta.mcp.write_enabled' => true,
    ]);

    $org = Organisation::factory()->create();
    $principal = Principal::factory()->create(['organisation_id' => $org->id]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    $abilities = [ApiKeyAbilityEnum::BrainRead->value];
    if ($withWriteAbility) {
        $abilities[] = ApiKeyAbilityEnum::BrainWrite->value;
    }

    $created = app(ApiKeyIssuerService::class)->create(
        principal: $principal,
        name: 'write',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $org->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
        abilities: $abilities,
    );

    return [
        'org' => $org,
        'principal' => $principal,
        'key' => $created['key'],
        'plain' => $created['plain'],
    ];
}

function writeAuth(array $seed): array
{
    return ['Authorization' => 'Bearer '.$seed['plain']];
}

it('refuses proposal create when api write kill switch is off', function (): void {
    $seed = writeSeedOwnerKey();
    config(['instituta.api.write_enabled' => false]);

    $this->postJson('/api/v1/proposals', [
        'action' => 'create',
        'stable_key' => 'rules/new',
        'title' => 'New rule',
        'path' => 'rules/new.md',
        'body' => '# secret body',
    ], writeAuth($seed))->assertForbidden();
});

it('refuses proposal create without brain:write ability', function (): void {
    $seed = writeSeedOwnerKey(withWriteAbility: false);

    $this->postJson('/api/v1/proposals', [
        'action' => 'create',
        'stable_key' => 'rules/new',
        'title' => 'New rule',
        'path' => 'rules/new.md',
    ], writeAuth($seed))->assertForbidden();
});

it('refuses issuing brain:write for guest keys even when writes are enabled', function (): void {
    config([
        'instituta.api.write_enabled' => true,
        'instituta.mcp.write_enabled' => true,
    ]);

    $org = Organisation::factory()->create();
    $principal = Principal::factory()->create(['organisation_id' => $org->id]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Guest,
        'max_visibility' => VisibilityEnum::Public,
    ]);

    expect(fn () => app(ApiKeyIssuerService::class)->create(
        principal: $principal,
        name: 'guest-write',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $org->id,
        role: RoleEnum::Guest,
        maxVisibility: VisibilityEnum::Public,
        abilities: [
            ApiKeyAbilityEnum::BrainRead->value,
            ApiKeyAbilityEnum::BrainWrite->value,
        ],
    ))->toThrow(InvalidArgumentException::class);
});

it('creates lists and accepts proposals without exposing body in responses or audit', function (): void {
    $seed = writeSeedOwnerKey();

    $create = $this->postJson('/api/v1/proposals', [
        'action' => 'create',
        'stable_key' => 'rules/coding',
        'title' => 'Coding rules',
        'path' => 'rules/coding.md',
        'area' => 'rules',
        'body' => '# Do not leak this',
    ], writeAuth($seed));

    $create->assertCreated()
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('stable_key', 'rules/coding')
        ->assertJsonMissingPath('body');

    $proposalId = $create->json('id');
    expect(Proposal::query()->whereKey($proposalId)->value('body'))->toBe('# Do not leak this');

    $this->getJson('/api/v1/proposals', writeAuth($seed))
        ->assertOk()
        ->assertJsonCount(1, 'proposals')
        ->assertJsonMissingPath('proposals.0.body');

    $this->patchJson('/api/v1/proposals/'.$proposalId, [
        'status' => 'accepted',
    ], writeAuth($seed))
        ->assertOk()
        ->assertJsonPath('status', 'accepted');

    expect(Proposal::query()->whereKey($proposalId)->value('status'))->toBe(ProposalStatusEnum::Accepted);

    $auditBodies = ContractCall::query()
        ->where('tool_or_endpoint', 'like', '%proposal%')
        ->get()
        ->map(fn (ContractCall $call): string => json_encode($call->getAttributes()))
        ->implode("\n");

    expect($auditBodies)->not->toContain('Do not leak this');
});

it('isolates proposals across organisations', function (): void {
    $seedA = writeSeedOwnerKey();
    $seedB = writeSeedOwnerKey();

    $created = $this->postJson('/api/v1/proposals', [
        'action' => 'update',
        'stable_key' => 'rules/a',
        'title' => 'A only',
        'path' => 'rules/a.md',
    ], writeAuth($seedA))->assertCreated();

    $this->getJson('/api/v1/proposals', writeAuth($seedB))
        ->assertOk()
        ->assertJsonCount(0, 'proposals');

    $this->patchJson('/api/v1/proposals/'.$created->json('id'), [
        'status' => 'rejected',
    ], writeAuth($seedB))->assertNotFound();
});

it('refuses brain:write on issue when all write kill switches are off', function (): void {
    config([
        'instituta.api.write_enabled' => false,
        'instituta.mcp.write_enabled' => false,
    ]);

    $org = Organisation::factory()->create();
    $principal = Principal::factory()->create(['organisation_id' => $org->id]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    expect(fn () => app(ApiKeyIssuerService::class)->create(
        principal: $principal,
        name: 'bad',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $org->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
        abilities: ['brain:write'],
    ))->toThrow(InvalidArgumentException::class);
});
