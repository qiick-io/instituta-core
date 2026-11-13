<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\BrainClientsService;

/**
 * Agency-portal decisions on products under a client company the actor owns.
 */
final class ProductPolicy
{
    public function __construct(
        private readonly BrainClientsService $clients,
    ) {}

    /**
     * Creating a product is gated by ownership of the parent client company.
     */
    public function create(User $user, Company $company): bool
    {
        return $this->clients->canOwnerManage($user, $company);
    }

    public function view(User $user, Product $product): bool
    {
        $company = $product->company;

        return $company instanceof Company
            && $this->clients->canOwnerManage($user, $company);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->view($user, $product);
    }
}
