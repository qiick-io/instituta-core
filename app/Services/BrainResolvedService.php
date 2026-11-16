<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\ScopeTypeEnum;
use App\Models\Company;
use App\Models\Item;
use App\Models\Product;

/**
 * Human UI resolved view: product > company > organisation keys with origin.
 */
final class BrainResolvedService
{
    /**
     * @return list<array{id: string, name: string, slug: string}>
     */
    public function products(PerimeterData $perimeter): array
    {
        $query = Product::query()->orderBy('name');

        match ($perimeter->scopeType) {
            ScopeTypeEnum::Product => $query->where('id', $perimeter->scopeId),
            ScopeTypeEnum::Company => $query->where('company_id', $perimeter->scopeId),
            ScopeTypeEnum::Organisation => $query->whereHas(
                'company',
                static fn ($q) => $q->where('organisation_id', $perimeter->scopeId),
            ),
        };

        /** @var list<array{id: string, name: string, slug: string}> $products */
        $products = $query->get(['id', 'name', 'slug'])
            ->map(static fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
            ])
            ->values()
            ->all();

        return $products;
    }

    /**
     * @return array{product_id: string|null, entries: list<array<string, mixed>>, token_estimate: int, agent_view: list<array<string, mixed>>}
     */
    public function resolve(PerimeterData $perimeter, ?string $productId): array
    {
        $products = $this->products($perimeter);
        if ($products === []) {
            return [
                'product_id' => null,
                'entries' => [],
                'token_estimate' => 0,
                'agent_view' => [],
            ];
        }

        $selectedId = $productId;
        if ($selectedId === null || $selectedId === '') {
            $selectedId = $products[0]['id'];
        }

        $allowedIds = array_column($products, 'id');
        if (! in_array($selectedId, $allowedIds, true)) {
            return [
                'product_id' => null,
                'entries' => [],
                'token_estimate' => 0,
                'agent_view' => [],
            ];
        }

        $product = Product::query()->with('company')->find($selectedId);
        if ($product === null) {
            return [
                'product_id' => null,
                'entries' => [],
                'token_estimate' => 0,
                'agent_view' => [],
            ];
        }

        $company = $product->company;
        if (! $company instanceof Company) {
            return [
                'product_id' => $product->id,
                'entries' => [],
                'token_estimate' => 0,
                'agent_view' => [],
            ];
        }

        $layers = $this->resolutionLayers($product, $company);
        /** @var array<string, array<string, mixed>> $resolved */
        $resolved = [];

        foreach (array_reverse($layers) as $layer) {
            $query = Item::query()
                ->where('organisation_id', $company->organisation_id)
                ->whereNotNull('resolution_key')
                ->where('resolution_key', '!=', '');

            match ($layer['scope_type']) {
                'product' => $query->where('product_id', $product->id),
                'company' => $query->where('company_id', $company->id)->whereNull('product_id'),
                'organisation' => $query->whereNull('company_id')->whereNull('product_id'),
                default => null,
            };

            $query->withinPerimeter($this->layerPerimeter($perimeter, $layer));

            foreach ($query->get() as $item) {
                /** @var Item $item */
                $resolved[(string) $item->resolution_key] = [
                    'id' => $item->id,
                    'resolution_key' => (string) $item->resolution_key,
                    'title' => $item->title,
                    'summary' => $item->summary,
                    'visibility' => $item->visibility->value,
                    'token_estimate' => $item->token_estimate,
                    'origin' => [
                        'scope_type' => $layer['scope_type'],
                        'scope_id' => $layer['scope_id'],
                        'path' => $item->path,
                        'commit' => $item->commit,
                    ],
                    'overridden' => $layer['scope_type'] === 'product',
                ];
            }
        }

        ksort($resolved);
        $entries = array_values($resolved);

        $agentView = array_map(static fn (array $entry): array => [
            'resolution_key' => $entry['resolution_key'],
            'title' => $entry['title'],
            'summary' => $entry['summary'],
            'origin' => $entry['origin']['scope_type'],
            'visibility' => $entry['visibility'],
        ], $entries);

        return [
            'product_id' => $product->id,
            'entries' => $entries,
            'token_estimate' => array_sum(array_column($entries, 'token_estimate')),
            'agent_view' => $agentView,
        ];
    }

    /**
     * @return list<array{scope_type: string, scope_id: string}>
     */
    private function resolutionLayers(Product $product, Company $company): array
    {
        $layers = [
            [
                'scope_type' => 'product',
                'scope_id' => $product->id,
            ],
        ];

        if ($product->inherits_from_parent) {
            $layers[] = [
                'scope_type' => 'company',
                'scope_id' => $company->id,
            ];
            $layers[] = [
                'scope_type' => 'organisation',
                'scope_id' => $company->organisation_id,
            ];
        }

        return $layers;
    }

    /**
     * @param  array{scope_type: string, scope_id: string}  $layer
     */
    private function layerPerimeter(PerimeterData $perimeter, array $layer): PerimeterData
    {
        return new PerimeterData(
            principalId: $perimeter->principalId,
            principalType: $perimeter->principalType,
            scopeType: ScopeTypeEnum::from($layer['scope_type']),
            scopeId: $layer['scope_id'],
            role: $perimeter->role,
            maxVisibility: $perimeter->maxVisibility,
            pathPrefix: $perimeter->pathPrefix,
            area: $perimeter->area,
        );
    }
}
