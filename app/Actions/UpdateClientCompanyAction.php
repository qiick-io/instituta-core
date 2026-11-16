<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ClientCompanyData;
use App\Models\Company;
use App\Models\User;
use App\Services\ActivityEventLoggerService;
use App\Services\LogoStorageService;
use Illuminate\Support\Facades\Gate;

/**
 * Updates a client company the caller owns (same organisation perimeter).
 */
final class UpdateClientCompanyAction
{
    public function __construct(
        private readonly ActivityEventLoggerService $activity,
        private readonly LogoStorageService $logos,
    ) {}

    public function execute(User $user, Company $company, ClientCompanyData $data): Company
    {
        Gate::forUser($user)->authorize('update', $company);

        $company->fill([
            'name' => $data->name,
            'slug' => $data->slug,
            'logo_path' => $this->logos->update($company->logo_path, $data->logo, $data->removeLogo, 'companies'),
        ]);

        $changedKeys = array_keys($company->getDirty());
        $company->save();

        if ($changedKeys !== []) {
            $this->activity->clientUpdated($company, $user, $changedKeys);
        }

        return $company;
    }
}
