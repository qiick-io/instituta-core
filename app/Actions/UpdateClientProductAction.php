<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ClientProductData;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\ActivityEventLoggerService;
use App\Services\LogoStorageService;
use Illuminate\Support\Facades\Gate;

/**
 * Updates a product that belongs to an owned client company.
 */
final class UpdateClientProductAction
{
    public function __construct(
        private readonly ActivityEventLoggerService $activity,
        private readonly LogoStorageService $logos,
    ) {}

    public function execute(
        User $user,
        Company $company,
        Product $product,
        ClientProductData $data,
    ): Product {
        if ($product->company_id !== $company->id) {
            abort(403);
        }

        Gate::forUser($user)->authorize('update', $product);

        $product->fill([
            'name' => $data->name,
            'slug' => $data->slug,
            'inherits_from_parent' => $data->inheritsFromParent,
            'logo_path' => $this->logos->update($product->logo_path, $data->logo, $data->removeLogo, 'products'),
        ]);

        $changedKeys = array_keys($product->getDirty());
        $product->save();

        if ($changedKeys !== []) {
            $this->activity->productUpdated($product, $company, $user, $changedKeys);
        }

        return $product;
    }
}
