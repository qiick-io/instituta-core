<?php

declare(strict_types=1);

use App\Brain\Data\PerimeterData;
use App\Brain\Data\ProductGroupData;
use App\Brain\Data\ResolutionContextData;
use App\Brain\Data\ScopeEntryData;
use App\Brain\Data\SharedSourceRefData;
use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Brain\Filters\PerimeterFilter;
use App\Brain\Resolvers\ScopeResolver;

function entry(
    string $key,
    ScopeTypeEnum $scopeType,
    string $scopeId,
    string $title,
    VisibilityEnum $visibility = VisibilityEnum::Internal,
    string $path = 'rules/item.md',
    string $area = 'rules',
    bool $shared = false,
    ?string $sharedSourceId = null,
    ?string $sharedVersion = null,
): ScopeEntryData {
    return new ScopeEntryData(
        resolutionKey: $key,
        title: $title,
        summary: $title.' summary',
        visibility: $visibility,
        scopeType: $scopeType,
        scopeId: $scopeId,
        source: $shared ? 'shared-source' : 'local',
        path: $path,
        commit: 'abc',
        area: $area,
        fromSharedSource: $shared,
        sharedSourceId: $sharedSourceId,
        sharedSourceVersionId: $sharedVersion,
    );
}

it('case 1: one product resolves with an empty organisation layer', function (): void {
    $resolver = new ScopeResolver;
    $resolved = $resolver->resolve(new ResolutionContextData(
        organisationId: 'org-1',
        companyId: 'co-1',
        productId: 'prod-1',
        inheritsFromParent: true,
        organisationEntries: [],
        companyEntries: [],
        productEntries: [
            entry('rules.coding', ScopeTypeEnum::Product, 'prod-1', 'Product coding'),
        ],
    ));

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]->resolutionKey)->toBe('rules.coding')
        ->and($resolved[0]->origin->scopeType)->toBe(ScopeTypeEnum::Product)
        ->and($resolved[0]->origin->scopeId)->toBe('prod-1');
});

it('case 2: product overrides organisation and keeps origins', function (): void {
    $resolver = new ScopeResolver;
    $resolved = $resolver->resolve(new ResolutionContextData(
        organisationId: 'org-1',
        companyId: 'co-1',
        productId: 'prod-a',
        organisationEntries: [
            entry('brand.voice', ScopeTypeEnum::Organisation, 'org-1', 'Org voice', path: 'style/voice.md', area: 'style'),
            entry('rules.coding', ScopeTypeEnum::Organisation, 'org-1', 'Org coding'),
        ],
        companyEntries: [],
        productEntries: [
            entry('rules.coding', ScopeTypeEnum::Product, 'prod-a', 'Product coding'),
        ],
    ));

    $byKey = collect($resolved)->keyBy(fn ($e) => $e->resolutionKey);

    expect($byKey->get('rules.coding')->title)->toBe('Product coding')
        ->and($byKey->get('rules.coding')->origin->scopeType)->toBe(ScopeTypeEnum::Product)
        ->and($byKey->get('brand.voice')->title)->toBe('Org voice')
        ->and($byKey->get('brand.voice')->origin->scopeType)->toBe(ScopeTypeEnum::Organisation);
});

it('case 3: inherits_from_parent false isolates the product; groups are explicit only', function (): void {
    $resolver = new ScopeResolver;
    $resolved = $resolver->resolve(new ResolutionContextData(
        organisationId: 'org-1',
        companyId: 'co-1',
        productId: 'prod-x',
        inheritsFromParent: false,
        organisationEntries: [
            entry('brand.voice', ScopeTypeEnum::Organisation, 'org-1', 'Org voice'),
        ],
        companyEntries: [
            entry('rules.coding', ScopeTypeEnum::Company, 'co-1', 'Company coding'),
        ],
        productEntries: [
            entry('rules.coding', ScopeTypeEnum::Product, 'prod-x', 'Isolated coding'),
        ],
        productGroups: [
            new ProductGroupData('group-a', 'Pair', ['prod-x', 'prod-y']),
            new ProductGroupData('group-b', 'Other', ['prod-z']),
        ],
    ));

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]->title)->toBe('Isolated coding')
        ->and($resolved[0]->origin->scopeType)->toBe(ScopeTypeEnum::Product);

    $groups = $resolver->groupsForProduct('prod-x', [
        new ProductGroupData('group-a', 'Pair', ['prod-x', 'prod-y']),
        new ProductGroupData('group-b', 'Other', ['prod-z']),
    ]);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]->id)->toBe('group-a');
});

it('case 5: a perimeter cannot be widened beyond its bound scope', function (): void {
    $filter = new PerimeterFilter;
    $perimeter = new PerimeterData(
        principalId: 'prin-1',
        principalType: PrincipalTypeEnum::Agent,
        scopeType: ScopeTypeEnum::Product,
        scopeId: 'prod-a',
        role: RoleEnum::Member,
        maxVisibility: VisibilityEnum::Internal,
    );

    expect(fn () => $filter->assertSingleScope($perimeter, ScopeTypeEnum::Product, 'prod-a'))
        ->not->toThrow(InvalidArgumentException::class);

    expect(fn () => $filter->assertSingleScope($perimeter, ScopeTypeEnum::Organisation, 'org-1'))
        ->toThrow(InvalidArgumentException::class);
});

it('case 6: shared sources are referenced by id and version, not copied', function (): void {
    $resolver = new ScopeResolver;
    $resolved = $resolver->resolve(new ResolutionContextData(
        organisationId: 'org-1',
        companyId: 'co-1',
        productId: 'prod-a',
        productEntries: [
            entry('rules.local', ScopeTypeEnum::Product, 'prod-a', 'Local rule'),
        ],
        sharedSourceRefs: [
            new SharedSourceRefData('src-shared', 'ver-1', 'prod-a'),
            new SharedSourceRefData('src-shared', 'ver-1', 'prod-b'), // other product
            new SharedSourceRefData('src-other', 'ver-9', 'prod-a'), // no matching entries
        ],
        sharedSourceEntries: [
            entry(
                'design.button',
                ScopeTypeEnum::Organisation,
                'org-1',
                'Button registry',
                path: 'registry.json',
                area: 'design-system',
                shared: true,
                sharedSourceId: 'src-shared',
                sharedVersion: 'ver-1',
            ),
            entry(
                'design.card',
                ScopeTypeEnum::Organisation,
                'org-1',
                'Wrong version',
                shared: true,
                sharedSourceId: 'src-shared',
                sharedVersion: 'ver-2',
            ),
        ],
    ));

    $byKey = collect($resolved)->keyBy(fn ($e) => $e->resolutionKey);

    expect($byKey)->toHaveCount(2)
        ->and($byKey->get('design.button')->fromSharedSource)->toBeTrue()
        ->and($byKey->get('design.button')->origin->source)->toBe('shared-source')
        ->and($byKey->has('design.card'))->toBeFalse();
});

it('case 7: public and internal items coexist; perimeter cuts by max visibility', function (): void {
    $filter = new PerimeterFilter;
    $entries = [
        entry('a.public', ScopeTypeEnum::Product, 'prod-1', 'Public', VisibilityEnum::Public, 'notes/public.md', 'notes'),
        entry('a.internal', ScopeTypeEnum::Product, 'prod-1', 'Internal', VisibilityEnum::Internal, 'notes/internal.md', 'notes'),
        entry('a.restricted', ScopeTypeEnum::Product, 'prod-1', 'Secret', VisibilityEnum::Restricted, 'notes/secret.md', 'notes'),
    ];

    $guest = new PerimeterData(
        principalId: 'prin-1',
        principalType: PrincipalTypeEnum::Person,
        scopeType: ScopeTypeEnum::Product,
        scopeId: 'prod-1',
        role: RoleEnum::Guest,
        maxVisibility: VisibilityEnum::Public,
    );

    $member = new PerimeterData(
        principalId: 'prin-2',
        principalType: PrincipalTypeEnum::Person,
        scopeType: ScopeTypeEnum::Product,
        scopeId: 'prod-1',
        role: RoleEnum::Member,
        maxVisibility: VisibilityEnum::Internal,
    );

    expect($filter->filterEntries($guest, $entries))->toHaveCount(1)
        ->and($filter->filterEntries($member, $entries))->toHaveCount(2);
});

it('case 10: perimeter applies visibility, area and path subtree together', function (): void {
    $filter = new PerimeterFilter;
    $resolver = new ScopeResolver;

    $context = new ResolutionContextData(
        organisationId: 'org-1',
        companyId: 'co-1',
        productId: 'prod-1',
        organisationEntries: [
            entry('brand.voice', ScopeTypeEnum::Organisation, 'org-1', 'Voice', path: 'style/voice.md', area: 'style'),
        ],
        productEntries: [
            entry('rules.coding', ScopeTypeEnum::Product, 'prod-1', 'Coding', path: 'rules/coding.md', area: 'rules'),
            entry('rules.secret', ScopeTypeEnum::Product, 'prod-1', 'Secret rule', VisibilityEnum::Restricted, 'rules/secret.md', 'rules'),
            entry('plans.roadmap', ScopeTypeEnum::Product, 'prod-1', 'Roadmap', path: 'plans/roadmap.md', area: 'plans'),
        ],
    );

    $resolved = $resolver->resolve($context);

    $perimeter = new PerimeterData(
        principalId: 'prin-1',
        principalType: PrincipalTypeEnum::Agent,
        scopeType: ScopeTypeEnum::Product,
        scopeId: 'prod-1',
        role: RoleEnum::Member,
        maxVisibility: VisibilityEnum::Internal,
        pathPrefixes: ['rules'],
        areas: ['rules'],
    );

    $visible = $filter->filterResolved($perimeter, $resolved);

    expect($visible)->toHaveCount(1)
        ->and($visible[0]->resolutionKey)->toBe('rules.coding');
});
