<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Enums\RoleEnum;
use App\Enums\CompanyKindEnum;
use App\Models\Company;
use App\Models\Principal;
use App\Models\User;

/**
 * Lists and authorises client companies for the agency portal (case 4).
 */
final class BrainClientsService
{
    /**
     * Organisation id when the user is an active person with an org-level owner grant.
     */
    public function ownerOrganisationIdFor(User $user): ?string
    {
        $principal = $user->principals()
            ->active()
            ->where('type', 'person')
            ->orderBy('id')
            ->first();

        if (! $principal instanceof Principal) {
            return null;
        }

        $ownerGrant = $principal->grants()
            ->active()
            ->where('role', RoleEnum::Owner->value)
            ->where('scope_type', 'organisation')
            ->orderBy('id')
            ->first();

        if ($ownerGrant === null) {
            return null;
        }

        return $principal->organisation_id;
    }

    /**
     * Whether the user may manage this company as a client of their organisation.
     */
    public function canOwnerManage(User $user, Company $company): bool
    {
        $organisationId = $this->ownerOrganisationIdFor($user);

        return $organisationId !== null
            && $company->organisation_id === $organisationId
            && $company->kind === CompanyKindEnum::Client;
    }

    /**
     * Soft-guard: abort when the company is outside the owner's client perimeter.
     */
    public function assertOwnerCanManage(User $user, Company $company): void
    {
        if (! $this->canOwnerManage($user, $company)) {
            abort(403);
        }
    }

    /**
     * @return array{
     *     is_owner: bool,
     *     clients: list<array{
     *         id: string,
     *         name: string,
     *         slug: string,
     *         products: list<array{id: string, name: string, slug: string}>
     *     }>
     * }
     */
    public function listFor(User $user): array
    {
        $organisationId = $this->ownerOrganisationIdFor($user);

        if ($organisationId === null) {
            return ['is_owner' => false, 'clients' => []];
        }

        $clients = Company::query()
            ->where('organisation_id', $organisationId)
            ->where('kind', CompanyKindEnum::Client)
            ->with(['products' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();

        /** @var list<array{id: string, name: string, slug: string, products: list<array{id: string, name: string, slug: string}>}> $rows */
        $rows = [];
        foreach ($clients as $client) {
            /** @var list<array{id: string, name: string, slug: string}> $products */
            $products = [];
            foreach ($client->products as $product) {
                $products[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                ];
            }

            $rows[] = [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'products' => $products,
            ];
        }

        return ['is_owner' => true, 'clients' => $rows];
    }
}
