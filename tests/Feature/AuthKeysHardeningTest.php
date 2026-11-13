<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\CompanyKindEnum;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use App\Services\ApiKeyIssuerService;
use App\Services\PrincipalLifecycleService;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;

it('does not expose public registration routes', function (): void {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Nope',
        'email' => 'nope@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});

it('bootstraps org company owner principal and key from instituta:install', function (): void {
    $envBackup = null;
    $envPath = base_path('.env');
    if (File::exists($envPath)) {
        $envBackup = File::get($envPath);
    }

    try {
        $this->artisan('instituta:install', [
            '--force' => true,
            '--no-npm' => true,
            '--org-name' => 'Acme Org',
            '--org-slug' => 'acme-org',
            '--company-name' => 'Acme Own',
            '--owner-name' => 'Ada Owner',
            '--owner-email' => 'ada@example.com',
            '--owner-password' => 'secret-owner-pass',
        ])->assertSuccessful();

        $org = Organisation::query()->where('slug', 'acme-org')->first();
        expect($org)->not->toBeNull();

        $company = Company::query()->where('organisation_id', $org->id)->first();
        expect($company)->not->toBeNull()
            ->and($company->kind)->toBe(CompanyKindEnum::Own)
            ->and($company->name)->toBe('Acme Own');

        $user = User::query()->where('email', 'ada@example.com')->first();
        expect($user)->not->toBeNull()
            ->and($user->name)->toBe('Ada Owner')
            ->and($user->email_verified_at)->not->toBeNull();

        $principal = Principal::query()->where('user_id', $user->id)->first();
        expect($principal)->not->toBeNull();

        expect(Grant::query()->where('principal_id', $principal->id)->exists())->toBeTrue();
        expect(ApiKey::query()->where('principal_id', $principal->id)->count())->toBe(1);

        expect(Activity::query()->where('description', 'api_key.created')->exists())->toBeTrue();
    } finally {
        if ($envBackup === null) {
            File::delete($envPath);
        } else {
            File::put($envPath, $envBackup);
        }
    }
});

it('rejects expired and revoked keys on REST', function (): void {
    $org = Organisation::factory()->create();
    $principal = Principal::factory()->create(['organisation_id' => $org->id]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    $issuer = app(ApiKeyIssuerService::class);
    $expired = $issuer->create(
        principal: $principal,
        name: 'expired',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $org->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );
    $expired['key']->forceFill(['expires_at' => now()->subMinute()])->save();

    $revoked = $issuer->create(
        principal: $principal,
        name: 'revoked',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $org->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );
    $issuer->revoke($revoked['key']);

    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$expired['plain']])
        ->assertUnauthorized();
    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$revoked['plain']])
        ->assertUnauthorized();

    expect(Activity::query()->where('description', 'api_key.revoked')->exists())->toBeTrue();
});

it('rejects expired and revoked keys on MCP', function (): void {
    config(['instituta.mcp.enabled' => true]);

    $org = Organisation::factory()->create();
    $principal = Principal::factory()->create(['organisation_id' => $org->id]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    $issuer = app(ApiKeyIssuerService::class);
    $expired = $issuer->create(
        principal: $principal,
        name: 'mcp-expired',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $org->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );
    $expired['key']->forceFill(['expires_at' => now()->subHour()])->save();

    $revoked = $issuer->create(
        principal: $principal,
        name: 'mcp-revoked',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $org->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );
    $issuer->revoke($revoked['key']);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '0'],
        ],
    ];

    $this->postJson('/mcp', $payload, [
        'Authorization' => 'Bearer '.$expired['plain'],
        'Accept' => 'application/json, text/event-stream',
    ])->assertUnauthorized();

    $this->postJson('/mcp', $payload, [
        'Authorization' => 'Bearer '.$revoked['plain'],
        'Accept' => 'application/json, text/event-stream',
    ])->assertUnauthorized();
});

it('rejects REST after principal deactivation cascade', function (): void {
    $org = Organisation::factory()->create();
    $person = Principal::factory()->create(['organisation_id' => $org->id]);
    Grant::factory()->create([
        'principal_id' => $person->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    $issued = app(ApiKeyIssuerService::class)->create(
        principal: $person,
        name: 'live',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $org->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );

    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$issued['plain']])
        ->assertOk();

    app(PrincipalLifecycleService::class)->deactivate($person);

    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$issued['plain']])
        ->assertUnauthorized();

    expect(Activity::query()->where('description', 'principal.deactivated')->exists())->toBeTrue();
});
