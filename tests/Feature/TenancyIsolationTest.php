<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Services\ApiKeyIssuerService;
use App\Services\PerimeterResolverService;
use App\Services\PrincipalLifecycleService;

it('isolates overlapping company and product slugs across organisations', function (): void {
    $orgA = Organisation::factory()->create(['slug' => 'acme', 'name' => 'Acme A']);
    $orgB = Organisation::factory()->create(['slug' => 'beta', 'name' => 'Beta B']);

    $companyA = Company::factory()->client()->create([
        'organisation_id' => $orgA->id,
        'slug' => 'client',
        'name' => 'Client A',
    ]);
    $companyB = Company::factory()->client()->create([
        'organisation_id' => $orgB->id,
        'slug' => 'client',
        'name' => 'Client B',
    ]);

    $productA = Product::factory()->create([
        'company_id' => $companyA->id,
        'slug' => 'app',
        'name' => 'App A',
    ]);
    $productB = Product::factory()->create([
        'company_id' => $companyB->id,
        'slug' => 'app',
        'name' => 'App B',
    ]);

    $sourceA = Source::factory()->create([
        'organisation_id' => $orgA->id,
        'company_id' => $companyA->id,
        'product_id' => $productA->id,
    ]);
    $sourceB = Source::factory()->create([
        'organisation_id' => $orgB->id,
        'company_id' => $companyB->id,
        'product_id' => $productB->id,
    ]);

    $itemA = Item::factory()->create([
        'organisation_id' => $orgA->id,
        'company_id' => $companyA->id,
        'product_id' => $productA->id,
        'source_id' => $sourceA->id,
        'title' => 'Secret A',
        'visibility' => VisibilityEnum::Internal,
        'area' => 'rules',
        'path' => 'rules/a.md',
    ]);
    $itemB = Item::factory()->create([
        'organisation_id' => $orgB->id,
        'company_id' => $companyB->id,
        'product_id' => $productB->id,
        'source_id' => $sourceB->id,
        'title' => 'Secret B',
        'visibility' => VisibilityEnum::Internal,
        'area' => 'rules',
        'path' => 'rules/b.md',
    ]);

    $principalA = Principal::factory()->create(['organisation_id' => $orgA->id]);
    Grant::factory()->create([
        'principal_id' => $principalA->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $orgA->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    $issuer = app(ApiKeyIssuerService::class);
    $created = $issuer->create(
        principal: $principalA,
        name: 'owner-a',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $orgA->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );

    $perimeter = app(PerimeterResolverService::class)->fromApiKey($created['key']->fresh('principal'));

    $visible = Item::query()->withinPerimeter($perimeter)->pluck('id')->all();

    expect($visible)->toContain($itemA->id)
        ->and($visible)->not->toContain($itemB->id)
        ->and(Company::query()->where('slug', 'client')->count())->toBe(2)
        ->and(Product::query()->where('slug', 'app')->count())->toBe(2);
});

it('stops keys when the owning person principal is deactivated', function (): void {
    $org = Organisation::factory()->create();
    $person = Principal::factory()->create(['organisation_id' => $org->id]);
    $agent = Principal::factory()->agent()->create([
        'organisation_id' => $org->id,
        'owner_principal_id' => $person->id,
    ]);

    Grant::factory()->create([
        'principal_id' => $person->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);
    Grant::factory()->create([
        'principal_id' => $agent->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Member,
        'max_visibility' => VisibilityEnum::Internal,
    ]);

    $issuer = app(ApiKeyIssuerService::class);
    $personKey = $issuer->create(
        principal: $person,
        name: 'person',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $org->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    )['key'];
    $agentKey = $issuer->create(
        principal: $agent,
        name: 'agent',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $org->id,
        role: RoleEnum::Member,
        maxVisibility: VisibilityEnum::Internal,
    )['key'];

    app(PrincipalLifecycleService::class)->deactivate($person);

    expect($person->fresh()->deactivated_at)->not->toBeNull()
        ->and($agent->fresh()->deactivated_at)->not->toBeNull()
        ->and($personKey->fresh()->revoked_at)->not->toBeNull()
        ->and($agentKey->fresh()->revoked_at)->not->toBeNull();

    expect(fn () => app(PerimeterResolverService::class)->fromApiKey($personKey->fresh('principal')))
        ->toThrow(InvalidArgumentException::class);
});

it('creates lists and revokes keys via artisan', function (): void {
    $org = Organisation::factory()->create();
    $principal = Principal::factory()->create(['organisation_id' => $org->id]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    $this->artisan('instituta:key:create', [
        'principal' => $principal->id,
        '--name' => 'cli',
        '--scope-type' => 'organisation',
        '--scope-id' => $org->id,
        '--role' => 'owner',
        '--max-visibility' => 'restricted',
    ])->assertSuccessful();

    $keyId = $principal->apiKeys()->value('id');
    expect($keyId)->not->toBeNull();

    $this->artisan('instituta:key:list', ['principal' => $principal->id])
        ->assertSuccessful();

    $this->artisan('instituta:key:revoke', ['key' => $keyId])
        ->assertSuccessful();

    expect($principal->apiKeys()->whereNotNull('revoked_at')->count())->toBe(1);
});

it('refuses brain:write ability on issue', function (): void {
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
