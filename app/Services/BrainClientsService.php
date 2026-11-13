<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Enums\RoleEnum;
use App\Enums\CompanyKindEnum;
use App\Models\Company;
use App\Models\Principal;
use App\Models\User;

/**
 * Lists client companies for the agency portal (case 4).
 */
final class BrainClientsService
{
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
        $principal = $user->principals()
            ->active()
            ->where('type', 'person')
            ->orderBy('id')
            ->first();

        if (! $principal instanceof Principal) {
            return ['is_owner' => false, 'clients' => []];
        }

        $ownerGrant = $principal->grants()
            ->active()
            ->where('role', RoleEnum::Owner->value)
            ->where('scope_type', 'organisation')
            ->orderBy('id')
            ->first();

        if ($ownerGrant === null) {
            return ['is_owner' => false, 'clients' => []];
        }

        $clients = Company::query()
            ->where('organisation_id', $principal->organisation_id)
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
