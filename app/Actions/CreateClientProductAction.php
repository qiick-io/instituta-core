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
 * Creates a product under an owned client company.
 */
final class CreateClientProductAction
{
    public function __construct(
        private readonly BrainClientsService $clients,
        private readonly ActivityEventLoggerService $activity,
    ) {}

    public function execute(User $user, Company $company, ClientProductData $data): Product
    {
        $this->clients->assertOwnerCanManage($user, $company);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'name' => $data->name,
            'slug' => $data->slug,
            'inherits_from_parent' => $data->inheritsFromParent,
        ]);

        $this->activity->productCreated($product, $company, $user);

        return $product;
    }
}
