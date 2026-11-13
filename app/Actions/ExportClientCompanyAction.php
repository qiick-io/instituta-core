<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Company;
use App\Models\OffboardingRequest;
use App\Models\User;
use App\Services\BrainClientsService;
use App\Services\ClientExportService;

/**
 * Runs a client-subtree export for an organisation owner (case 8 portal).
 */
final class ExportClientCompanyAction
{
    public function __construct(
        private readonly BrainClientsService $clients,
        private readonly ClientExportService $exporter,
    ) {}

    /**
     * @return array{path: string, request: OffboardingRequest, manifest: array<string, mixed>}
     */
    public function execute(User $user, Company $company, ?string $destination = null): array
    {
        $this->clients->assertOwnerCanManage($user, $company);

        return $this->exporter->export($company, $destination, $user);
    }
}
