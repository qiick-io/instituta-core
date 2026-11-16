<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\PermissionEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Models\User;
use App\Services\GlobalSearchService;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;

/**
 * @return list<string>
 */
function globalSearchLabels(array $answer, string $type): array
{
    foreach ($answer['groups'] as $group) {
        if ($group['type'] === $type) {
            return array_column($group['items'], 'label');
        }
    }

    return [];
}

/**
 * @return array{user: User, itemA: Item, itemB: Item}
 */
function globalSearchSeed(): array
{
    $user = User::factory()->create();
    $orgA = Organisation::factory()->create(['name' => 'Org A']);
    $orgB = Organisation::factory()->create(['name' => 'Org B']);
    $companyA = Company::factory()->create(['organisation_id' => $orgA->id]);
    $companyB = Company::factory()->create(['organisation_id' => $orgB->id]);
    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
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

    $principal = Principal::factory()->create([
        'organisation_id' => $orgA->id,
        'user_id' => $user->id,
        'type' => 'person',
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $orgA->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    $itemA = Item::factory()->create([
        'organisation_id' => $orgA->id,
        'company_id' => $companyA->id,
        'product_id' => $productA->id,
        'source_id' => $sourceA->id,
        'title' => 'Billing rules',
        'area' => 'rules',
        'visibility' => VisibilityEnum::Internal,
    ]);
    $itemB = Item::factory()->create([
        'organisation_id' => $orgB->id,
        'company_id' => $companyB->id,
        'product_id' => $productB->id,
        'source_id' => $sourceB->id,
        'title' => 'Billing secrets',
        'area' => 'rules',
        'visibility' => VisibilityEnum::Internal,
    ]);

    return compact('user', 'itemA', 'itemB');
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->withoutVite();
});

it('finds brain item titles inside the session perimeter only', function () {
    $seed = globalSearchSeed();

    $answer = $this->actingAs($seed['user'])
        ->getJson(route('search', ['query' => 'billing']))
        ->assertOk()
        ->json();

    expect(globalSearchLabels($answer, 'items'))->toBe(['Billing rules'])
        ->and($answer['groups'][0]['items'][0]['url'])
        ->toBe(route('explorer.show', ['item' => $seed['itemA']->id], false))
        ->and($answer['groups'][0]['items'][0])->not->toHaveKey('body')
        ->and($answer['groups'][0]['items'][0])->not->toHaveKey('summary');
});

it('never returns items from another organisation', function () {
    $seed = globalSearchSeed();

    $answer = $this->actingAs($seed['user'])
        ->getJson(route('search', ['query' => 'secrets']))
        ->assertOk()
        ->json();

    expect(globalSearchLabels($answer, 'items'))->toBe([]);
});

it('caps each group', function () {
    $seed = globalSearchSeed();

    Item::factory()->count(GlobalSearchService::LIMIT_PER_GROUP + 2)->create([
        'organisation_id' => $seed['itemA']->organisation_id,
        'company_id' => $seed['itemA']->company_id,
        'product_id' => $seed['itemA']->product_id,
        'source_id' => $seed['itemA']->source_id,
        'title' => 'Palette item match',
        'visibility' => VisibilityEnum::Internal,
    ]);

    $answer = $this->actingAs($seed['user'])
        ->getJson(route('search', ['query' => 'Palette']))
        ->assertOk()
        ->json();

    expect(globalSearchLabels($answer, 'items'))
        ->toHaveCount(GlobalSearchService::LIMIT_PER_GROUP);
});

it('needs two characters and a signed-in user', function () {
    $seed = globalSearchSeed();

    $this->actingAs($seed['user'])
        ->getJson(route('search', ['query' => 'a']))
        ->assertUnprocessable();

    auth()->logout();

    $this->getJson(route('search', ['query' => 'billing']))
        ->assertUnauthorized();
});

it('returns users only to who may list them', function () {
    $seed = globalSearchSeed();
    User::factory()->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
    ]);

    $withoutPermission = $this->actingAs($seed['user'])
        ->getJson(route('search', ['query' => 'ada']))
        ->assertOk()
        ->json();

    expect(globalSearchLabels($withoutPermission, 'users'))->toBe([]);

    $adminRole = Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $adminRole->givePermissionTo(PermissionEnum::CanShowUsers->value);
    $seed['user']->assignRole($adminRole);

    $withPermission = $this->actingAs($seed['user']->fresh())
        ->getJson(route('search', ['query' => 'ada']))
        ->assertOk()
        ->json();

    expect(globalSearchLabels($withPermission, 'users'))->toContain('Ada Lovelace');
});
