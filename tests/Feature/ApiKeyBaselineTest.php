<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\RevokeApiKeyAction;
use App\Actions\RotateApiKeyAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use App\Services\ApiKeyIssuerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{org: Organisation, principal: Principal, user: User}
 */
function baselineKeyContext(RoleEnum $role = RoleEnum::Owner): array
{
    $org = Organisation::factory()->create();
    $user = User::factory()->create();
    $principal = Principal::factory()->create([
        'organisation_id' => $org->id,
        'user_id' => $user->id,
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => $role,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    return compact('org', 'principal', 'user');
}

/**
 * @param  list<string>  $scopes
 * @return array{key: ApiKey, plain: string, org: Organisation, principal: Principal, user: User}
 */
function baselineIssueKey(array $scopes = [ApiKeyScopeEnum::BrainRead->value], RoleEnum $role = RoleEnum::Owner): array
{
    $context = baselineKeyContext($role);
    $issued = app(CreateApiKeyAction::class)->execute(
        principal: $context['principal'],
        name: 'baseline',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $context['org']->id,
        role: $role,
        maxVisibility: VisibilityEnum::Restricted,
        scopes: $scopes,
        createdBy: $context['user'],
    );

    return [...$context, ...$issued];
}

it('issues the fleet token format and stores SHA-256 of the full token', function (): void {
    $issued = baselineIssueKey();
    $plain = $issued['plain'];
    $key = $issued['key'];

    expect($plain)->toMatch(ApiKeyIssuerService::TOKEN_PATTERN)
        ->and($key->key_prefix)->toMatch('/^ins_test_[A-Za-z0-9]{8}$/D')
        ->and($key->key_hash)->toBe(hash('sha256', $plain))
        ->and($key->key_prefix)->not->toBe(substr($plain, -8))
        ->and($key->scopes)->toBe([ApiKeyScopeEnum::BrainRead->value]);
});

it('refuses a token with a trailing newline at the validation boundary', function (): void {
    $issued = baselineIssueKey();
    $issuer = app(ApiKeyIssuerService::class);
    $withNewline = $issued['plain']."\n";

    expect(preg_match(ApiKeyIssuerService::TOKEN_PATTERN, $withNewline))->toBe(0)
        ->and($issuer->publicPartFromToken($withNewline))->toBeNull()
        ->and($issuer->findByPlainSecret($withNewline))->toBeNull();

    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$withNewline])
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token');
});

it('never writes the plaintext into the activity log or model attributes', function (): void {
    $issued = baselineIssueKey();
    $plain = $issued['plain'];
    $key = $issued['key']->fresh();

    expect($key->getAttributes())->not->toHaveKey('plain')
        ->and(json_encode($key->getAttributes()))->not->toContain($plain);

    $activities = Activity::query()
        ->where('log_name', 'api-keys')
        ->where('subject_id', $key->id)
        ->get();

    expect($activities)->not->toBeEmpty();

    foreach ($activities as $activity) {
        expect(json_encode($activity->properties->toArray()))->not->toContain($plain)
            ->and((string) $activity->description)->not->toContain($plain);
    }
});

it('refuses expired, revoked and unknown keys with RFC 6750 invalid_token', function (): void {
    $expired = baselineIssueKey();
    $expired['key']->forceFill(['expires_at' => now()->subMinute()])->save();

    $revoked = baselineIssueKey();
    app(RevokeApiKeyAction::class)->execute($revoked['key']);

    foreach ([
        [$expired['plain'], 'expired'],
        [$revoked['plain'], 'revoked'],
        ['ins_test_deadbeef'.str_repeat('a', 32), 'unknown'],
    ] as [$token, $label]) {
        $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$token])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_token')
            ->assertJsonPath('code', 'unauthenticated')
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    }
});

it('refuses an empty scope list and a missing brain:read with insufficient_scope', function (): void {
    $context = baselineKeyContext();

    expect(fn () => app(CreateApiKeyAction::class)->execute(
        principal: $context['principal'],
        name: 'empty',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $context['org']->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
        scopes: [],
    ))->toThrow(InvalidArgumentException::class, 'API key scopes must not be empty.');

    $issued = baselineIssueKey([ApiKeyScopeEnum::BrainRead->value]);
    $issued['key']->forceFill(['scopes' => []])->save();

    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$issued['plain']])
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope')
        ->assertJsonPath('scope', 'brain:read')
        ->assertJsonPath('code', 'ability_missing');
});

it('lets brain:write satisfy brain:read and refuses write when only read is held', function (): void {
    config(['instituta.api.write_enabled' => true, 'instituta.mcp.write_enabled' => true]);

    $writeKey = baselineIssueKey([ApiKeyScopeEnum::BrainWrite->value]);
    expect($writeKey['key']->scopes)->toBe([ApiKeyScopeEnum::BrainWrite->value])
        ->and($writeKey['key']->hasScope(ApiKeyScopeEnum::BrainRead))->toBeTrue()
        ->and($writeKey['key']->hasScope(ApiKeyScopeEnum::BrainWrite))->toBeTrue();

    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$writeKey['plain']])
        ->assertOk();

    $readKey = baselineIssueKey([ApiKeyScopeEnum::BrainRead->value]);
    expect($readKey['key']->hasScope(ApiKeyScopeEnum::BrainWrite))->toBeFalse();
});

it('keeps the previous secret working inside the grace window and not after', function (): void {
    $issued = baselineIssueKey();
    $oldPlain = $issued['plain'];

    $rotated = app(RotateApiKeyAction::class)->execute($issued['key'], $issued['user']);
    $newPlain = $rotated['plain'];

    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$oldPlain])->assertOk();
    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$newPlain])->assertOk();

    $rotated['key']->forceFill(['previous_key_valid_until' => now()->subSecond()])->save();

    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$oldPlain])
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token');
    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$newPlain])->assertOk();
});

it('clears the grace window on revoke so the previous secret dies immediately', function (): void {
    $issued = baselineIssueKey();
    $rotated = app(RotateApiKeyAction::class)->execute($issued['key'], $issued['user']);
    $oldPlain = $issued['plain'];

    app(RevokeApiKeyAction::class)->execute($rotated['key'], $issued['user']);

    expect($rotated['key']->fresh()->previous_key_hash)->toBeNull()
        ->and($rotated['key']->fresh()->previous_key_valid_until)->toBeNull();

    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$oldPlain])
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token');
    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$rotated['plain']])
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token');
});

it('refuses API-key auth for key management and does not establish a web session', function (): void {
    $issued = baselineIssueKey();

    $this->postJson('/settings/api-keys', [
        'name' => 'minted-by-key',
        'principal' => 'me',
        'role' => 'owner',
        'max_visibility' => 'restricted',
    ], ['Authorization' => 'Bearer '.$issued['plain']])
        ->assertUnauthorized();

    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$issued['plain']])
        ->assertOk();

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('refuses brain:write on a guest principal', function (): void {
    config(['instituta.api.write_enabled' => true, 'instituta.mcp.write_enabled' => true]);

    expect(fn () => baselineIssueKey([ApiKeyScopeEnum::BrainWrite->value], RoleEnum::Guest))
        ->toThrow(InvalidArgumentException::class);
});

it('enforces rate_limit_per_minute and records last_used_ip', function (): void {
    $issued = baselineIssueKey();
    $issued['key']->forceFill(['rate_limit_per_minute' => 1])->save();
    RateLimiter::clear('api-key:'.$issued['key']->id);

    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$issued['plain']])
        ->assertOk();

    $this->getJson('/api/v1/scopes', ['Authorization' => 'Bearer '.$issued['plain']])
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited');

    expect($issued['key']->fresh()->last_used_ip)->not->toBeNull()
        ->and($issued['key']->fresh()->last_used_at)->not->toBeNull();
});

it('looks keys up by key_prefix without scanning hashes', function (): void {
    $issuer = app(ApiKeyIssuerService::class);
    $secret = $issuer->makeSecret();
    $other = $issuer->makeSecret();

    expect($secret['key_prefix'])->not->toBe($other['key_prefix'])
        ->and($issuer->publicPartFromToken($secret['plain']))->toBe($secret['key_prefix'])
        ->and($issuer->publicPartFromToken('not-a-token'))->toBeNull();
});
