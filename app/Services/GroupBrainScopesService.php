<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Enums\ScopeTypeEnum;
use App\Enums\CompanyKindEnum;
use App\Models\Company;
use App\Models\Organisation;
use App\Models\User;

/**
 * The brain scopes a person may give a user group access to (#258): the organisation they own
 * and its client companies. Feeds the group drawer's options and the request's validation, so a
 * group never reaches past what its editor owns.
 */
final class GroupBrainScopesService
{
    public function __construct(
        private readonly BrainClientsService $clients,
        private readonly BrainMapService $map,
    ) {}

    /**
     * The areas a group's access may be limited to (#334): those of the organisation the person owns.
     *
     * @return list<string>
     */
    public function areasFor(User $user): array
    {
        $organisationId = $this->clients->ownerOrganisationIdFor($user);

        return $organisationId === null ? [] : $this->map->areasOf($organisationId);
    }

    /**
     * @return list<array{value: string, label: string, organisation_id: string, scope_type: string, scope_id: string}>
     */
    public function optionsFor(User $user): array
    {
        $organisationId = $this->clients->ownerOrganisationIdFor($user);
        $organisation = $organisationId === null ? null : Organisation::query()->find($organisationId);

        if ($organisation === null) {
            return [];
        }

        $options = [self::option(ScopeTypeEnum::Organisation, $organisation->id, $organisation->name, $organisation->id)];

        $clients = Company::query()
            ->where('organisation_id', $organisation->id)
            ->where('kind', CompanyKindEnum::Client)
            ->orderBy('name')
            ->get(['id', 'name']);

        foreach ($clients as $client) {
            $options[] = self::option(ScopeTypeEnum::Company, $client->id, $client->name, $organisation->id);
        }

        return $options;
    }

    /**
     * The option a stored access stands for, by its `scope_type:scope_id` value.
     *
     * @return array{value: string, label: string, organisation_id: string, scope_type: string, scope_id: string}|null
     */
    public function find(User $user, string $value): ?array
    {
        foreach ($this->optionsFor($user) as $option) {
            if ($option['value'] === $value) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @return array{value: string, label: string, organisation_id: string, scope_type: string, scope_id: string}
     */
    private static function option(ScopeTypeEnum $scopeType, string $scopeId, string $label, string $organisationId): array
    {
        return [
            'value' => $scopeType->value.':'.$scopeId,
            'label' => $label,
            'organisation_id' => $organisationId,
            'scope_type' => $scopeType->value,
            'scope_id' => $scopeId,
        ];
    }
}
