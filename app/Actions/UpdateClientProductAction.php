<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ClientProductData;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\ActivityEventLoggerService;
use App\Services\BrainClientsService;

/**
 * Updates a product that belongs to an owned client company.
 */
final class UpdateClientProductAction
{
    public function __construct(
        private readonly BrainClientsService $clients,
        private readonly ActivityEventLoggerService $activity,
    ) {}

    public function execute(
        User $user,
        Company $company,
        Product $product,
        ClientProductData $data,
    ): Product {
        $this->clients->assertOwnerCanManage($user, $company);

        if ($product->company_id !== $company->id) {
            abort(403);
        }

        $product->fill([
            'name' => $data->name,
            'slug' => $data->slug,
            'inherits_from_parent' => $data->inheritsFromParent,
        ]);

        $changedKeys = array_keys($product->getDirty());
        $product->save();

        if ($changedKeys !== []) {
            $this->activity->productUpdated($product, $company, $user, $changedKeys);
        }

        return $product;
    }
}
