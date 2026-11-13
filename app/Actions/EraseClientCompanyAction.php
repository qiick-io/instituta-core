<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Company;
use App\Models\OffboardingRequest;
use App\Models\User;
use App\Services\BrainClientsService;
use App\Services\ClientErasureService;

/**
 * Erases an exported client subtree for an organisation owner.
 */
final class EraseClientCompanyAction
{
    public function __construct(
        private readonly BrainClientsService $clients,
        private readonly ClientErasureService $erasure,
    ) {}

    /**
     * @return array{request: OffboardingRequest, deleted_items: int, revoked_keys: int}
     */
    public function execute(User $user, Company $company, OffboardingRequest $request): array
    {
        $this->clients->assertOwnerCanManage($user, $company);

        if ($request->company_id !== $company->id) {
            abort(404);
        }

        return $this->erasure->erase($request, $user);
    }
}
