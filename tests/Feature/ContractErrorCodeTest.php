<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;

/**
 * Every REST error carries a stable machine `error` next to its message (fleet core hardening).
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $principal = Principal::factory()->create(['organisation_id' => $this->organisation->id]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $this->organisation->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);
    $this->plainKey = app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: 'reader',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $this->organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
        scopes: [ApiKeyScopeEnum::BrainRead->value],
    )['plain'];
});

it('codes a missing or invalid key as unauthenticated', function (): void {
    $this->getJson('/api/v1/scopes')->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer nope'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
});

it('codes an unmatched API route as JSON without an Accept header', function (): void {
    $this->get('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('error', 'not_found');
});

it('codes failed validation and keeps the field errors', function (): void {
    $this->getJson('/api/v1/search', ['Authorization' => 'Bearer '.$this->plainKey])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonValidationErrors(['q']);
});

it('codes an item outside the perimeter as not found', function (): void {
    $this->getJson('/api/v1/items/01JZZZZZZZZZZZZZZZZZZZZZZZ', ['Authorization' => 'Bearer '.$this->plainKey])
        ->assertNotFound()
        ->assertJsonPath('error', 'not_found');
});

it('codes the write gate refusals', function (): void {
    config(['instituta.api.write_enabled' => false]);
    $this->getJson('/api/v1/proposals', ['Authorization' => 'Bearer '.$this->plainKey])
        ->assertForbidden()
        ->assertJsonPath('error', 'write_disabled');

    config(['instituta.api.write_enabled' => true]);
    $this->getJson('/api/v1/proposals', ['Authorization' => 'Bearer '.$this->plainKey])
        ->assertForbidden()
        ->assertJsonPath('error', 'ability_missing');
});

it('codes disabled embeddings on the similar endpoint', function (): void {
    config(['ai.embeddings.enabled' => false]);

    $this->getJson('/api/v1/similar?q=brand&scope='.$this->organisation->id, ['Authorization' => 'Bearer '.$this->plainKey])
        ->assertStatus(501)
        ->assertJsonPath('error', 'embeddings_disabled');
});
