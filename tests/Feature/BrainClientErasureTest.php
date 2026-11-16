<?php

declare(strict_types=1);

use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\OffboardingStatusEnum;
use App\Models\AgentSession;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\ContractCall;
use App\Models\Grant;
use App\Models\Item;
use App\Models\OffboardingRequest;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Models\User;
use App\Models\VaultVersion;
use App\Services\ClientErasureService;
use App\Services\ClientExportService;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @return array{org: Organisation, user: User}
 */
function clientErasureOwnerContext(): array
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

    return compact('org', 'user');
}

it('erases client A content and keys without touching client B or own company', function (): void {
    ['org' => $org, 'user' => $user] = clientErasureOwnerContext();

    $clientA = Company::factory()->client()->create([
        'organisation_id' => $org->id,
        'slug' => 'erase-a',
    ]);
    $clientB = Company::factory()->client()->create([
        'organisation_id' => $org->id,
        'slug' => 'erase-b',
    ]);
    $own = Company::factory()->own()->create([
        'organisation_id' => $org->id,
        'slug' => 'erase-own',
    ]);

    $productA = Product::factory()->create(['company_id' => $clientA->id]);
    $productB = Product::factory()->create(['company_id' => $clientB->id]);
    $productOwn = Product::factory()->create(['company_id' => $own->id]);

    $sourceA = Source::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $clientA->id,
        'product_id' => $productA->id,
    ]);
    $sourceB = Source::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $clientB->id,
        'product_id' => $productB->id,
    ]);
    $sourceOwn = Source::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $own->id,
        'product_id' => $productOwn->id,
    ]);

    Item::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $clientA->id,
        'product_id' => $productA->id,
        'source_id' => $sourceA->id,
        'title' => 'A only',
        'path' => 'rules/a.md',
        'stable_key' => 'rules/a',
    ]);
    Item::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $clientB->id,
        'product_id' => $productB->id,
        'source_id' => $sourceB->id,
        'title' => 'B only',
        'path' => 'rules/b.md',
        'stable_key' => 'rules/b',
    ]);
    Item::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $own->id,
        'product_id' => $productOwn->id,
        'source_id' => $sourceOwn->id,
        'title' => 'Own only',
        'path' => 'rules/own.md',
        'stable_key' => 'rules/own',
    ]);

    $agent = Principal::factory()->create([
        'organisation_id' => $org->id,
        'type' => PrincipalTypeEnum::Agent,
        'user_id' => null,
    ]);
    Grant::factory()->create([
        'principal_id' => $agent->id,
        'scope_type' => ScopeTypeEnum::Company,
        'scope_id' => $clientA->id,
        'role' => RoleEnum::Member,
        'max_visibility' => VisibilityEnum::Internal,
    ]);
    $keyA = ApiKey::factory()->create([
        'principal_id' => $agent->id,
        'scope_type' => ScopeTypeEnum::Company,
        'scope_id' => $clientA->id,
        'revoked_at' => null,
    ]);
    $keyB = ApiKey::factory()->create([
        'principal_id' => $agent->id,
        'scope_type' => ScopeTypeEnum::Company,
        'scope_id' => $clientB->id,
        'revoked_at' => null,
    ]);

    $vault = VaultVersion::factory()->create(['organisation_id' => $org->id]);
    AgentSession::factory()->create([
        'api_key_id' => $keyA->id,
        'scope_type' => ScopeTypeEnum::Company,
        'scope_id' => $clientA->id,
        'vault_version_id' => $vault->id,
        'invalidated_at' => null,
    ]);
    ContractCall::factory()->create([
        'api_key_id' => $keyA->id,
        'scope_type' => ScopeTypeEnum::Company,
        'scope_id' => $clientA->id,
        'organisation_id' => $org->id,
        'company_id' => $clientA->id,
        'product_id' => $productA->id,
        'tool_or_endpoint' => 'list',
    ]);
    ContractCall::factory()->create([
        'api_key_id' => $keyB->id,
        'scope_type' => ScopeTypeEnum::Company,
        'scope_id' => $clientB->id,
        'organisation_id' => $org->id,
        'company_id' => $clientB->id,
        'product_id' => $productB->id,
        'tool_or_endpoint' => 'list',
    ]);

    $dest = storage_path('framework/testing/exports/erase-a');
    File::deleteDirectory($dest);
    $export = app(ClientExportService::class)->export($clientA, $dest);
    $request = $export['request'];

    $this->actingAs($user)
        ->post(route('clients.export.erase', [$clientA, $request]))
        ->assertRedirect(route('clients.index'));

    $request->refresh();
    expect($request->status)->toBe(OffboardingStatusEnum::Erased)
        ->and($request->erased_at)->not->toBeNull()
        ->and(Item::query()->where('title', 'A only')->exists())->toBeFalse()
        ->and(Item::query()->where('title', 'B only')->exists())->toBeTrue()
        ->and(Item::query()->where('title', 'Own only')->exists())->toBeTrue()
        ->and(Source::query()->whereKey($sourceA->id)->exists())->toBeFalse()
        ->and(Source::query()->whereKey($sourceB->id)->exists())->toBeTrue()
        ->and(ContractCall::query()->where('company_id', $clientA->id)->exists())->toBeFalse()
        ->and(ContractCall::query()->where('company_id', $clientB->id)->exists())->toBeTrue();

    $keyA->refresh();
    $keyB->refresh();
    expect($keyA->revoked_at)->not->toBeNull()
        ->and($keyB->revoked_at)->toBeNull();

    $agent->refresh();
    expect($agent->deactivated_at)->toBeNull();

    $this->artisan('instituta:erase', [
        'offboarding' => OffboardingRequest::factory()->create([
            'company_id' => $clientB->id,
            'status' => OffboardingStatusEnum::Exported,
            'export_path' => storage_path('framework/testing/exports/erase-b'),
        ])->id,
    ])->assertSuccessful();

    expect(Item::query()->where('title', 'B only')->exists())->toBeFalse()
        ->and(Item::query()->where('title', 'Own only')->exists())->toBeTrue();
});

it('forbids erase outside owner perimeter and before export', function (): void {
    ['org' => $org, 'user' => $user] = clientErasureOwnerContext();

    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
    ]);
    $pending = OffboardingRequest::factory()->create([
        'company_id' => $client->id,
        'status' => OffboardingStatusEnum::Pending,
        'export_path' => null,
    ]);

    expect(fn () => app(ClientErasureService::class)->erase($pending))
        ->toThrow(InvalidArgumentException::class);

    $this->actingAs($user)
        ->post(route('clients.export.erase', [$client, $pending]))
        ->assertStatus(422);

    $foreignOrg = Organisation::factory()->create();
    $foreignClient = Company::factory()->client()->create([
        'organisation_id' => $foreignOrg->id,
    ]);
    $foreignExport = OffboardingRequest::factory()->create([
        'company_id' => $foreignClient->id,
        'status' => OffboardingStatusEnum::Exported,
        'export_path' => storage_path('framework/testing/foreign-erase'),
    ]);

    $this->actingAs($user)
        ->post(route('clients.export.erase', [$foreignClient, $foreignExport]))
        ->assertForbidden();
});
