<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Lists products under a client company for the agency portal.
 */
final class BrainClientProductsService
{
    /**
     * @return array{
     *     client: array{id: string, name: string, slug: string, logo_url: string|null},
     *     products: list<array{
     *         id: string,
     *         name: string,
     *         slug: string,
     *         logo_url: string|null,
     *         inherits_from_parent: bool
     *     }>
     * }
     */
    public function listFor(User $user, Company $company): array
    {
        Gate::forUser($user)->authorize('view', $company);

        $products = Product::query()
            ->where('company_id', $company->id)
            ->orderBy('name')
            ->get();

        /** @var list<array{id: string, name: string, slug: string, logo_url: string|null, inherits_from_parent: bool}> $rows */
        $rows = [];
        foreach ($products as $product) {
            $rows[] = [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'logo_url' => app(LogoStorageService::class)->url($product->logo_path),
                'inherits_from_parent' => $product->inherits_from_parent,
            ];
        }

        return [
            'client' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'logo_url' => app(LogoStorageService::class)->url($company->logo_path),
            ],
            'products' => $rows,
        ];
    }
}
