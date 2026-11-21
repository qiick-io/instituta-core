<?php

declare(strict_types=1);

use App\Enums\CompanyKindEnum;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\QueryException;

it('factories create tenancy graph and index rows', function (): void {
    $organisation = Organisation::factory()->create();
    $company = Company::factory()->own()->create([
        'organisation_id' => $organisation->id,
        'slug' => 'acme',
    ]);
    $client = Company::factory()->client()->create([
        'organisation_id' => $organisation->id,
        'slug' => 'acme-client',
    ]);
    $product = Product::factory()->create([
        'company_id' => $company->id,
        'slug' => 'platform',
    ]);
    $principal = Principal::factory()->person()->create([
        'organisation_id' => $organisation->id,
    ]);
    $apiKey = ApiKey::factory()->create([
        'owner_principal_id' => $principal->id,
        'scope_id' => $organisation->id,
    ]);
    $source = Source::factory()->create([
        'organisation_id' => $organisation->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
    ]);
    $item = Item::factory()->create([
        'organisation_id' => $organisation->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
        'source_id' => $source->id,
    ]);

    expect($organisation->wasRecentlyCreated)->toBeTrue();
    expect($company->kind)->toBe(CompanyKindEnum::Own);
    expect($client->kind)->toBe(CompanyKindEnum::Client);
    expect($apiKey->getAttributes()['key_hash'] ?? null)->not->toBeEmpty();
    expect(strlen($apiKey->key_prefix))->toBeGreaterThanOrEqual(12);
    $this->assertDatabaseHas('items', [
        'id' => $item->id,
        'organisation_id' => $organisation->id,
        'product_id' => $product->id,
        'source_id' => $source->id,
    ]);
});

it('refuses a second principal for the same user in the same organisation', function (): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->create();

    Principal::factory()->person()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $user->id,
    ]);

    Principal::factory()->person()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $user->id,
    ]);
})->throws(QueryException::class);
