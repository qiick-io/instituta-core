<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Company;
use App\Models\OffboardingRequest;
use App\Models\User;
use App\Services\ClientExportService;
use Illuminate\Support\Facades\Gate;

/**
 * Runs a client-subtree export for an organisation owner (case 8 portal).
 */
final class ExportClientCompanyAction
{
    public function __construct(
        private readonly ClientExportService $exporter,
    ) {}

    /**
     * @return array{path: string, request: OffboardingRequest, manifest: array<string, mixed>}
     */
    public function execute(User $user, Company $company, ?string $destination = null): array
    {
        Gate::forUser($user)->authorize('update', $company);

        return $this->exporter->export($company, $destination, $user);
    }
}
