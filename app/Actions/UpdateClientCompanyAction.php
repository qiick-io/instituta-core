<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ClientCompanyData;
use App\Models\Company;
use App\Models\User;
use App\Services\ActivityEventLoggerService;
use App\Services\BrainClientsService;

/**
 * Updates a client company the caller owns (same organisation perimeter).
 */
final class UpdateClientCompanyAction
{
    public function __construct(
        private readonly BrainClientsService $clients,
        private readonly ActivityEventLoggerService $activity,
    ) {}

    public function execute(User $user, Company $company, ClientCompanyData $data): Company
    {
        $this->clients->assertOwnerCanManage($user, $company);

        $company->fill([
            'name' => $data->name,
            'slug' => $data->slug,
        ]);

        $changedKeys = array_keys($company->getDirty());
        $company->save();

        if ($changedKeys !== []) {
            $this->activity->clientUpdated($company, $user, $changedKeys);
        }

        return $company;
    }
}
