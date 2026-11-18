<?php

declare(strict_types=1);

use App\Brain\Enums\ScopeTypeEnum;
use App\Models\Company;
use App\Models\ContractCall;
use App\Models\GapReport;
use App\Models\Item;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\User;
use App\Services\PerimeterResolverService;
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
    // Marketing (#334) reads brand, style and the glossary, never the rules.
    $marketer = User::query()->where('email', 'giulia@northwind.example')->firstOrFail();
    $this->actingAs($marketer)->get(route('explorer.show', 'rules/coding'))->assertNotFound();
    $principal = $marketer->principals()->sole();
    $perimeter = app(PerimeterResolverService::class)->fromGrants($principal, $principal->grants()->get(), ScopeTypeEnum::Organisation, $principal->organisation_id);
    expect($perimeter->areas)->toBe(['brand', 'style', 'glossary'])
        ->and(Item::query()->withinPerimeter($perimeter)->distinct()->pluck('area')->sort()->values()->all())->toBe(['glossary', 'style']);
});
