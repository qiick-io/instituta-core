<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Services\BrainClientsService;

/**
 * Agency-portal decisions on client companies: the actor must be an organisation
 * owner and the company must be a client of that organisation.
 */
final class CompanyPolicy
{
    public function __construct(
        private readonly BrainClientsService $clients,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->clients->ownerOrganisationIdFor($user) !== null;
    }

    public function create(User $user): bool
    {
        return $this->clients->ownerOrganisationIdFor($user) !== null;
    }

    public function view(User $user, Company $company): bool
    {
        return $this->clients->canOwnerManage($user, $company);
    }

    public function update(User $user, Company $company): bool
    {
        return $this->clients->canOwnerManage($user, $company);
    }

    public function delete(User $user, Company $company): bool
    {
        return $this->clients->canOwnerManage($user, $company);
    }
}
