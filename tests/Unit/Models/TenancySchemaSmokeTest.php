<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\CompanyKindEnum;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancySchemaSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_factories_create_tenancy_graph_and_index_rows(): void
    {
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
            'principal_id' => $principal->id,
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

        $this->assertTrue($organisation->wasRecentlyCreated);
        $this->assertSame(CompanyKindEnum::Own, $company->kind);
        $this->assertSame(CompanyKindEnum::Client, $client->kind);
        $this->assertNotEmpty($apiKey->getAttributes()['hash'] ?? null);
        $this->assertSame(12, strlen($apiKey->display_prefix));
        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'organisation_id' => $organisation->id,
            'product_id' => $product->id,
            'source_id' => $source->id,
        ]);
    }
}
