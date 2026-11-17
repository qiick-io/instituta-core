<?php

declare(strict_types=1);

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Company;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Services\IndexedContractService;

/**
 * A lookup by stable key or path when an organisation rule and its client and product overrides
 * share the key (#328): the perimeter's own item wins, every time.
 */
function sharedKeyBrain(): array
{
    $organisation = Organisation::factory()->create();
    $company = Company::factory()->client()->create(['organisation_id' => $organisation->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $principal = Principal::factory()->create(['organisation_id' => $organisation->id]);

    // Created narrowest first, so insertion order cannot make the test pass by accident.
    $items = [];

    foreach ([
        'product' => ['company_id' => $company->id, 'product_id' => $product->id],
        'company' => ['company_id' => $company->id, 'product_id' => null],
        'organisation' => ['company_id' => null, 'product_id' => null],
    ] as $level => $scopeColumns) {
        $source = Source::factory()->create(['organisation_id' => $organisation->id, ...$scopeColumns]);
        $items[$level] = Item::factory()->create([
            'source_id' => $source->id,
            'organisation_id' => $organisation->id,
            ...$scopeColumns,
            'path' => 'rules/coding.md',
            'stable_key' => 'rules/coding',
            'title' => ucfirst($level).' coding rules',
        ]);
    }

    $perimeterFor = static fn (ScopeTypeEnum $scopeType, string $scopeId): PerimeterData => new PerimeterData(
        principalId: $principal->id,
        principalType: PrincipalTypeEnum::Agent,
        scopeType: $scopeType,
        scopeId: $scopeId,
        role: RoleEnum::Member,
        maxVisibility: VisibilityEnum::Internal,
    );

    return [$items, $perimeterFor, $organisation, $company, $product];
}

it('reads the perimeter’s own item when overrides share its stable key or path', function (): void {
    [$items, $perimeterFor, $organisation, $company] = sharedKeyBrain();
    $contract = app(IndexedContractService::class);

    $atOrganisation = $perimeterFor(ScopeTypeEnum::Organisation, $organisation->id);
    $atCompany = $perimeterFor(ScopeTypeEnum::Company, $company->id);

    expect($contract->get($atOrganisation, null, 'rules/coding')['id'])->toBe($items['organisation']->id)
        ->and($contract->get($atOrganisation, null, 'rules/coding.md')['id'])->toBe($items['organisation']->id)
        ->and($contract->get($atCompany, null, 'rules/coding')['id'])->toBe($items['company']->id);
});

it('reads exactly the item an id names, whatever its scope', function (): void {
    [$items, $perimeterFor, $organisation] = sharedKeyBrain();

    $item = app(IndexedContractService::class)->get(
        $perimeterFor(ScopeTypeEnum::Organisation, $organisation->id),
        null,
        $items['product']->id,
    );

    expect($item['id'])->toBe($items['product']->id);
});

it('orders matches from the widest scope to the narrowest', function (): void {
    [$items, $perimeterFor, $organisation] = sharedKeyBrain();

    $titles = Item::query()
        ->withinPerimeter($perimeterFor(ScopeTypeEnum::Organisation, $organisation->id))
        ->matchingReference('rules/coding')
        ->pluck('title')
        ->all();

    expect($titles)->toBe(['Organisation coding rules', 'Company coding rules', 'Product coding rules']);
});
