<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ClientCompanyData;
use App\Enums\CompanyKindEnum;
use App\Models\Company;
use App\Models\User;
use App\Services\ActivityEventLoggerService;
use App\Services\BrainClientsService;
use Illuminate\Support\Facades\Gate;

/**
 * Creates a client company inside the caller's organisation perimeter.
 */
final class CreateClientCompanyAction
{
    public function __construct(
        private readonly BrainClientsService $clients,
        private readonly ActivityEventLoggerService $activity,
    ) {}

    public function execute(User $user, ClientCompanyData $data): Company
    {
        Gate::forUser($user)->authorize('create', Company::class);

        $organisationId = $this->clients->ownerOrganisationIdFor($user);

        if ($organisationId === null) {
            abort(403);
        }

        $company = Company::query()->create([
            'organisation_id' => $organisationId,
            'name' => $data->name,
            'slug' => $data->slug,
            'kind' => CompanyKindEnum::Client,
        ]);

        $this->activity->clientCreated($company, $user);

        return $company;
    }
}
