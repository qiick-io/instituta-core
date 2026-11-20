<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Jobs\SyncSourceJob;
use App\Models\ApiKey;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Owners manage sources and API keys from the web UI (#201).
 */
beforeEach(function (): void {
    $this->withoutVite();
    config(['instituta.ingest.denylist' => ['acme', 'globex', 'initech']]);
    $this->organisation = Organisation::factory()->create();
    $this->owner = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($this->owner, $this->organisation, RoleEnum::Owner);
});

function organisationMember(Organisation $organisation): User
{
    $member = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($member, $organisation, RoleEnum::Member);

    return $member;
}

/**
 * @return array<string, mixed>
 */
function githubSourcePayload(array $overrides = []): array
{
    return [
        'name' => 'Company brain',
        'provider' => 'github',
        'remote_url' => 'https://github.com/example/brain',
        'ref' => 'main',
        'adapter' => 'instituta',
        'default_visibility' => 'internal',
        'is_shared' => true,
        ...$overrides,
    ];
}

it('lets owners add a source with the provider defaults and refuses members', function (): void {
    $this->actingAs($this->owner)->get(route('repositories.index'))->assertInertia(fn (Assert $page) => $page->where('canManage', true));

    $this->actingAs($this->owner)
        ->post(route('repositories.store'), githubSourcePayload())
        ->assertRedirect(route('repositories.index'));

    $source = Source::query()->sole();
    expect($source->organisation_id)->toBe($this->organisation->id)
        ->and($source->scope_type)->toBe(ScopeTypeEnum::Organisation)
        ->and($source->sync_interval_minutes)->toBe(15)
        ->and($source->is_shared)->toBeTrue();

    $member = organisationMember($this->organisation);
    $this->actingAs($member)->get(route('repositories.index'))->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->actingAs($member)->post(route('repositories.store'), githubSourcePayload(['name' => 'Other']))->assertForbidden();
});

it('validates the location and refuses denylisted sources', function (): void {
    $this->actingAs($this->owner)
        ->post(route('repositories.store'), githubSourcePayload(['remote_url' => '']))
        ->assertSessionHasErrors('remote_url');

    $this->actingAs($this->owner)
        ->post(route('repositories.store'), githubSourcePayload(['remote_url' => 'https://github.com/example/globex-docs']))
        ->assertSessionHasErrors('name');

    expect(Source::query()->count())->toBe(0);
});

it('lets owners edit, sync and remove a source, and nobody else', function (): void {
    Queue::fake();
    $source = Source::factory()->github()->create(['organisation_id' => $this->organisation->id, 'sync_interval_minutes' => 15]);
    $item = Item::factory()->create(['organisation_id' => $this->organisation->id, 'source_id' => $source->id]);
    $outsider = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($outsider, Organisation::factory()->create(), RoleEnum::Owner);

    $this->actingAs($outsider)->put(route('repositories.update', $source), githubSourcePayload())->assertForbidden();
    $this->actingAs(organisationMember($this->organisation))->post(route('repositories.sync', $source))->assertForbidden();

    $this->actingAs($this->owner)
        ->put(route('repositories.update', $source), githubSourcePayload(['name' => 'Renamed', 'ref' => 'develop', 'sync_interval_minutes' => 0]))
        ->assertRedirect(route('repositories.index'));
    expect($source->fresh()->name)->toBe('Renamed')
        ->and($source->fresh()->ref)->toBe('develop')
        ->and($source->fresh()->sync_interval_minutes)->toBeNull();

    $this->actingAs($this->owner)->post(route('repositories.sync', $source))->assertRedirect(route('repositories.index'));
    Queue::assertPushed(SyncSourceJob::class, fn (SyncSourceJob $job): bool => $job->sourceId === $source->id && $job->isForced);

    $this->actingAs($outsider)->delete(route('repositories.destroy', $source))->assertForbidden();
    $this->actingAs($this->owner)->delete(route('repositories.destroy', $source))->assertRedirect(route('repositories.index'));

    expect(Source::query()->find($source->id))->toBeNull()
        ->and(Item::query()->find($item->id))->toBeNull();
});

it('creates a key for a new agent, shows the secret once and stores only its hash', function (): void {
    $this->actingAs($this->owner)
        ->post(route('api-keys.store'), [
            'name' => 'Cursor laptop',
            'principal' => 'agent',
            'agent_name' => 'Cursor',
            'role' => 'member',
            'max_visibility' => 'internal',
            'can_write' => false,
            'expires_at' => now()->addMonth()->toDateString(),
        ])
        ->assertRedirect(route('api-keys.index'));

    $agent = Principal::query()->where('type', PrincipalTypeEnum::Agent)->sole();
    $key = ApiKey::query()->sole();

    expect($agent->owner_principal_id)->toBe($this->owner->principals()->sole()->id)
        ->and($agent->name)->toBe('Cursor')
        ->and(Grant::query()->where('principal_id', $agent->id)->sole()->role)->toBe(RoleEnum::Member)
        ->and($key->principal_id)->toBe($agent->id)
        ->and($key->scopes)->toBe([ApiKeyScopeEnum::BrainRead->value])
        ->and($key->expires_at)->not->toBeNull();

    $this->actingAs($this->owner)
        ->get(route('api-keys.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('plainTextKey', fn (?string $plain): bool => $plain !== null && ! str_contains($key->key_hash, $plain))
            ->where('keys.0.principal_name', 'Cursor')
            ->where('keys.0.principal_type', 'agent'));

    $this->actingAs($this->owner)
        ->get(route('api-keys.index'))
        ->assertInertia(fn (Assert $page) => $page->where('plainTextKey', null));
});

it('hands the new secret to the keys page through the session', function (): void {
    $this->actingAs($this->owner)
        ->post(route('api-keys.store'), [
            'name' => 'Mine',
            'principal' => 'me',
            'role' => 'owner',
            'max_visibility' => 'restricted',
        ])
        ->assertSessionHas('plain_text_api_key', fn (string $plain): bool => str_starts_with($plain, ApiKey::DEFAULT_PREFIX)
            && ! str_contains((string) ApiKey::query()->sole()->key_hash, $plain));

    expect(ApiKey::query()->sole()->principal_id)->toBe($this->owner->principals()->sole()->id);
});

it('refuses a write key while writes are switched off, and members entirely', function (): void {
    config(['instituta.api.write_enabled' => false, 'instituta.mcp.write_enabled' => false]);

    $this->actingAs($this->owner)
        ->post(route('api-keys.store'), ['name' => 'Writer', 'principal' => 'agent', 'agent_name' => 'Bot', 'role' => 'member', 'max_visibility' => 'internal', 'can_write' => true])
        ->assertSessionHasErrors(['name' => 'Ability brain:write is refused while writes are disabled.']);

    expect(ApiKey::query()->count())->toBe(0)
        ->and(Principal::query()->where('type', PrincipalTypeEnum::Agent)->count())->toBe(0);

    $this->actingAs(organisationMember($this->organisation))
        ->post(route('api-keys.store'), ['name' => 'Nope', 'principal' => 'me', 'role' => 'member', 'max_visibility' => 'internal'])
        ->assertForbidden();
});

it('lets the owner revoke an agent key they own, but not someone else\'s', function (): void {
    $this->actingAs($this->owner)->post(route('api-keys.store'), ['name' => 'Agent key', 'principal' => 'agent', 'agent_name' => 'Bot', 'role' => 'member', 'max_visibility' => 'internal']);
    $key = ApiKey::query()->sole();
    $otherOwner = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($otherOwner, $this->organisation, RoleEnum::Owner);

    $this->actingAs($otherOwner)->delete(route('api-keys.destroy', $key))->assertForbidden();
    $this->actingAs($this->owner)->delete(route('api-keys.destroy', $key))->assertRedirect(route('api-keys.index'));

    expect($key->fresh()->revoked_at)->not->toBeNull();
});
