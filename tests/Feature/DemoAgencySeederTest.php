<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\ContractCall;
use App\Models\GapReport;
use App\Models\Item;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\DemoAgencySeeder;
use Illuminate\Support\Facades\Storage;

/**
 * The demo agency for screenshots and demos (#323) seeds a working, fictional brain.
 */
it('seeds Northwind Studio with clients, brains, a review inbox and real agent calls', function (): void {
    Storage::fake('local');
    $this->withoutVite();

    $this->seed(DemoAgencySeeder::class);

    expect(Company::query()->where('kind', 'client')->count())->toBe(3)
        ->and(Product::query()->count())->toBe(7)
        ->and(Item::query()->where('stable_key', 'rules/coding')->count())->toBe(4)
        ->and(Item::query()->where('type', 'image')->count())->toBe(1)
        ->and(Proposal::query()->count())->toBe(3)
        ->and(GapReport::query()->count())->toBe(1)
        ->and(ContractCall::query()->distinct()->pluck('tool_or_endpoint')->all())->toContain('instituta_search', 'instituta_context');

    $this->actingAs(User::query()->where('email', DemoAgencySeeder::OWNER_EMAIL)->firstOrFail())
        ->get(route('explorer.show', 'rules/coding'))
        ->assertOk();
});
