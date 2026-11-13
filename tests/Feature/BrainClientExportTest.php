<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Item;
use App\Models\OffboardingRequest;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @return array{org: Organisation, user: User}
 */
function clientExportOwnerContext(): array
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

it('exports client A from the portal without client B data', function (): void {
    ['org' => $org, 'user' => $user] = clientExportOwnerContext();

    $clientA = Company::factory()->client()->create([
        'organisation_id' => $org->id,
        'slug' => 'portal-a',
    ]);
    $clientB = Company::factory()->client()->create([
        'organisation_id' => $org->id,
        'slug' => 'portal-b',
    ]);

    $productA = Product::factory()->create(['company_id' => $clientA->id]);
    $sourceA = Source::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $clientA->id,
        'product_id' => $productA->id,
        'name' => 'source-a',
    ]);
    $sourceB = Source::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $clientB->id,
        'name' => 'source-b',
    ]);

    Item::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $clientA->id,
        'product_id' => $productA->id,
        'source_id' => $sourceA->id,
        'title' => 'Only A',
        'path' => 'rules/a.md',
        'stable_key' => 'rules/a',
    ]);
    Item::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $clientB->id,
        'source_id' => $sourceB->id,
        'title' => 'Only B',
        'path' => 'rules/b.md',
        'stable_key' => 'rules/b',
    ]);

    $this->actingAs($user)
        ->post(route('brain.clients.export.store', $clientA))
        ->assertRedirect(route('brain.clients.index', [
            'exported' => OffboardingRequest::query()->where('company_id', $clientA->id)->value('id'),
        ]));

    $request = OffboardingRequest::query()->where('company_id', $clientA->id)->first();
    expect($request)->not->toBeNull()
        ->and($request->export_path)->not->toBeNull();

    $index = json_decode(File::get($request->export_path.'/index.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(collect($index['items'])->pluck('title')->all())->toBe(['Only A'])
        ->and(collect($index['items'])->pluck('title')->all())->not->toContain('Only B');

    $this->actingAs($user)
        ->get(route('brain.clients.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('clients', function ($clients) use ($clientA, $request): bool {
                $row = collect($clients)->firstWhere('id', $clientA->id);

                return is_array($row) && ($row['latest_export_id'] ?? null) === $request->id;
            }));

    $download = $this->actingAs($user)
        ->get(route('brain.clients.export.download', [$clientA, $request]))
        ->assertOk();

    $zipPath = storage_path('framework/testing/portal-a-download.zip');
    File::put($zipPath, $download->streamedContent());
    $archive = new ZipArchive;
    expect($archive->open($zipPath))->toBeTrue();
    $manifest = json_decode((string) $archive->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($manifest['company']['id'])->toBe($clientA->id)
        ->and(collect($manifest['sources'])->pluck('id')->all())->toBe([$sourceA->id]);
    $archive->close();
});

it('forbids export and download outside the owner perimeter', function (): void {
    ['org' => $org, 'user' => $user] = clientExportOwnerContext();

    $foreignOrg = Organisation::factory()->create();
    $foreignClient = Company::factory()->client()->create([
        'organisation_id' => $foreignOrg->id,
    ]);
    $foreignExport = OffboardingRequest::factory()->create([
        'company_id' => $foreignClient->id,
        'export_path' => storage_path('framework/testing/foreign-export'),
    ]);
    File::ensureDirectoryExists($foreignExport->export_path);
    File::put($foreignExport->export_path.'/manifest.json', "{}\n");

    $this->actingAs($user)
        ->post(route('brain.clients.export.store', $foreignClient))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('brain.clients.export.download', [$foreignClient, $foreignExport]))
        ->assertForbidden();

    $member = User::factory()->create();
    $principal = Principal::factory()->create([
        'organisation_id' => $org->id,
        'user_id' => $member->id,
        'type' => 'person',
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Member,
        'max_visibility' => VisibilityEnum::Internal,
    ]);
    $client = Company::factory()->client()->create([
        'organisation_id' => $org->id,
    ]);

    $this->actingAs($member)
        ->post(route('brain.clients.export.store', $client))
        ->assertForbidden();
});
