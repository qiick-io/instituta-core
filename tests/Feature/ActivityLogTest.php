<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\RevokeApiKeyAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceProviderEnum;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Source;
use App\Models\User;
use App\Models\VaultVersion;
use App\Services\ActivityEventLoggerService;
use App\Services\ClientExportService;
use App\Services\ContextFreezeService;
use App\Services\PrincipalLifecycleService;
use App\Services\SourceRegistryService;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @return array{org: Organisation, user: User, principal: Principal}
 */
function activityOwnerContext(): array
{
    $org = Organisation::factory()->create();
    $user = User::factory()->create();
    $principal = Principal::factory()->create([
        'organisation_id' => $org->id,
        'user_id' => $user->id,
        'type' => 'person',
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    return compact('org', 'user', 'principal');
}

it('logs login and failed login under the auth log', function (): void {
    $user = User::factory()->create([
        'password' => 'password',
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect();

    expect(Activity::query()
        ->where('log_name', 'auth')
        ->where('event', 'login')
        ->where('causer_id', $user->id)
        ->exists())->toBeTrue();

    $this->post(route('logout'));

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    expect(Activity::query()
        ->where('log_name', 'auth')
        ->where('event', 'failed')
        ->exists())->toBeTrue();
});

it('logs grant creation and principal deactivation', function (): void {
    ['org' => $org, 'user' => $user, 'principal' => $principal] = activityOwnerContext();
    $this->actingAs($user);

    $grant = Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Member,
    ]);

    expect(Activity::query()
        ->where('subject_type', $grant->getMorphClass())
        ->where('subject_id', $grant->id)
        ->where('event', 'created')
        ->where('organisation_id', $org->id)
        ->exists())->toBeTrue();

    app(PrincipalLifecycleService::class)->deactivate($principal);

    expect(Activity::query()
        ->where('event', 'principal.deactivated')
        ->where('organisation_id', $org->id)
        ->exists())->toBeTrue();
});

it('logs api key create and revoke with organisation stamp', function (): void {
    ['org' => $org, 'principal' => $principal] = activityOwnerContext();

    $issuer = app(CreateApiKeyAction::class);
    $revoke = app(RevokeApiKeyAction::class);
    $created = $issuer->execute(
        principal: $principal,
        name: 'audit-key',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $org->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
    );

    expect(Activity::query()
        ->where('event', 'api_key.created')
        ->where('organisation_id', $org->id)
        ->exists())->toBeTrue();

    $revoke->execute($created['key']);

    expect(Activity::query()
        ->where('event', 'api_key.revoked')
        ->where('organisation_id', $org->id)
        ->exists())->toBeTrue();
});

it('logs source add, freeze, unfreeze and export events', function (): void {
    ['org' => $org, 'user' => $user, 'principal' => $principal] = activityOwnerContext();
    $this->actingAs($user);

    $source = app(SourceRegistryService::class)->add(
        organisation: $org,
        name: 'fixture',
        provider: SourceProviderEnum::Local,
        localPath: base_path('tests/Brain/Fixtures'),
    );

    expect(Activity::query()
        ->where('event', 'source.added')
        ->where('organisation_id', $org->id)
        ->where('subject_id', $source->id)
        ->exists())->toBeTrue();

    $vault = VaultVersion::factory()->create([
        'organisation_id' => $org->id,
    ]);

    $freeze = app(ContextFreezeService::class)->freeze(
        vault: $vault,
        principal: $principal,
        reason: 'audit test',
    );

    expect(Activity::query()
        ->where('event', 'freeze.created')
        ->where('organisation_id', $org->id)
        ->exists())->toBeTrue();

    app(ContextFreezeService::class)->unfreeze($freeze, $principal);

    expect(Activity::query()
        ->where('event', 'freeze.lifted')
        ->where('organisation_id', $org->id)
        ->exists())->toBeTrue();

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
    ]);

    $exportRoot = storage_path('framework/testing/activity-export-'.uniqid());
    File::ensureDirectoryExists($exportRoot);

    try {
        app(ClientExportService::class)->export($client, $exportRoot);

        expect(Activity::query()
            ->where('event', 'export.completed')
            ->where('organisation_id', $org->id)
            ->exists())->toBeTrue();
    } finally {
        File::deleteDirectory($exportRoot);
    }
});

it('logs settings key changes without secret values and proposal helper events', function (): void {
    ['org' => $org, 'user' => $user] = activityOwnerContext();
    $this->actingAs($user);

    $this->patch(route('profile.update'), [
        'first_name' => 'Renamed',
        'last_name' => 'Owner',
        'email' => $user->email,
        'locale' => 'en',
        'timezone' => 'UTC',
    ])->assertRedirect();

    $settings = Activity::query()
        ->where('event', 'settings.updated')
        ->where('organisation_id', $org->id)
        ->latest('id')
        ->first();

    expect($settings)->not->toBeNull();
    $meta = $settings->properties?->toArray() ?? [];
    expect($meta['keys'] ?? null)->toContain('first_name')
        ->and($meta)->not->toHaveKey('first_name');

    $logger = app(ActivityEventLoggerService::class);
    $subject = Source::factory()->create(['organisation_id' => $org->id]);

    $logger->proposalOpened($subject, $user, ['organisation_id' => $org->id, 'proposal_id' => 'p1']);
    $logger->proposalAccepted($subject, $user, ['organisation_id' => $org->id, 'proposal_id' => 'p1']);
    $logger->proposalRejected($subject, $user, ['organisation_id' => $org->id, 'proposal_id' => 'p1']);
    $logger->scannerSkips($subject, [['path' => 'secret.env', 'reason' => 'pii']]);
    $logger->principalExpired($user->principals()->first());
    $logger->twoFactorEnabled($user, $org->id);
    $logger->twoFactorDisabled($user, $org->id);

    foreach ([
        'proposal.opened',
        'proposal.accepted',
        'proposal.rejected',
        'source.scanner_skip',
        'principal.expired',
        'two_factor.enabled',
        'two_factor.disabled',
    ] as $event) {
        expect(Activity::query()->where('event', $event)->where('organisation_id', $org->id)->exists())->toBeTrue();
    }
});

it('scopes the activity page to the owner organisation and hides other tenants', function (): void {
    ['org' => $orgA, 'user' => $ownerA] = activityOwnerContext();
    ['org' => $orgB, 'user' => $ownerB] = activityOwnerContext();

    activity('keys')
        ->causedBy($ownerA)
        ->event('api_key.created')
        ->withProperties(['organisation_id' => $orgA->id])
        ->log('org-a-only');

    activity('keys')
        ->causedBy($ownerB)
        ->event('api_key.created')
        ->withProperties(['organisation_id' => $orgB->id])
        ->log('org-b-only');

    $this->actingAs($ownerA)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('activity/index')
            ->where('is_owner', true)
            ->has('activityLogs.data')
            ->where('activityLogs.data', function ($rows) use ($orgA): bool {
                $descriptions = collect($rows)->pluck('description')->all();

                return in_array('org-a-only', $descriptions, true)
                    && ! in_array('org-b-only', $descriptions, true)
                    && collect($rows)->every(fn ($row) => ($row['organisation_id'] ?? null) === $orgA->id
                        || ! array_key_exists('organisation_id', $row));
            }));

    $member = User::factory()->create();
    $memberPrincipal = Principal::factory()->create([
        'organisation_id' => $orgA->id,
        'user_id' => $member->id,
        'type' => 'person',
    ]);
    Grant::factory()->create([
        'principal_id' => $memberPrincipal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $orgA->id,
        'role' => RoleEnum::Member,
        'max_visibility' => VisibilityEnum::Internal,
    ]);

    $this->actingAs($member)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('activity/index')
            ->where('is_owner', false)
            ->where('activityLogs.data', []));
});

it('filters activity logs by log name and event', function (): void {
    ['org' => $org, 'user' => $user] = activityOwnerContext();
    $this->actingAs($user);

    activity('auth')
        ->causedBy($user)
        ->event('login')
        ->withProperties(['organisation_id' => $org->id])
        ->log('login-row');

    activity('keys')
        ->causedBy($user)
        ->event('api_key.created')
        ->withProperties(['organisation_id' => $org->id])
        ->log('key-row');

    $this->get(route('activity.index', ['log_name' => 'auth', 'event' => 'login']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('activity/index')
            ->has('activityLogs.data', 1)
            ->where('activityLogs.data.0.description', 'login-row'));
});
