<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Principal;
use App\Models\Product;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Resolves the single request perimeter from a key or from the principal's grants on a scope.
 */
final class PerimeterResolverService
{
    /**
     * @var array<string, int>
     */
    private const VISIBILITY_RANK = [
        'public' => 0,
        'internal' => 1,
        'restricted' => 2,
    ];

    /**
     * @var array<string, int>
     */
    private const ROLE_RANK = [
        'guest' => 0,
        'member' => 1,
        'owner' => 2,
    ];

    /**
     * The key's perimeter: what the principal's grants on the key's scope allow, narrowed by the
     * key's own role, visibility, areas and path prefixes (#334). A key never widens its grants.
     *
     * @throws InvalidArgumentException When the key is unusable or reaches past its grants.
     */
    public function fromApiKey(ApiKey $key): PerimeterData
    {
        $principal = $key->principal;
        if ($principal === null) {
            throw new InvalidArgumentException('API key has no principal.');
        }

        $this->assertPrincipalUsable($principal);

        if ($key->revoked_at !== null) {
            throw new InvalidArgumentException('API key is revoked.');
        }

        if ($key->expires_at !== null && $key->expires_at->isPast()) {
            throw new InvalidArgumentException('API key is expired.');
        }

        $grants = $this->grantsCovering($principal, $key->scope_type, $key->scope_id);
        if ($grants->isEmpty()) {
            throw new InvalidArgumentException('API key perimeter is not covered by a grant.');
        }

        $granted = $this->fromGrants($principal, $grants, $key->scope_type, $key->scope_id);

        if (! $this->roleNotWider($key->role, $granted->role)) {
            throw new InvalidArgumentException('API key role is wider than the principal grant.');
        }

        if (! $this->visibilityNotWider($key->max_visibility, $granted->maxVisibility)) {
            throw new InvalidArgumentException('API key visibility is wider than the principal grant.');
        }

        $areas = self::narrowAreas($granted->areas, $key->areas);
        $pathPrefixes = self::narrowPathPrefixes($granted->pathPrefixes, $key->path_prefixes);

        if ($areas === null || $pathPrefixes === null) {
            throw new InvalidArgumentException('API key areas or paths are outside the principal grant.');
        }

        return new PerimeterData(
            principalId: $principal->id,
            principalType: $principal->type,
            scopeType: $key->scope_type,
            scopeId: $key->scope_id,
            role: $key->role,
            maxVisibility: $key->max_visibility,
            pathPrefixes: $pathPrefixes,
            areas: $areas,
        );
    }

    public function fromGrant(Principal $principal, Grant $grant): PerimeterData
    {
        return $this->fromGrants($principal, collect([$grant]), $grant->scope_type, $grant->scope_id);
    }

    /**
     * One perimeter from every grant that covers a scope, for example a person's own grant and
     * the one a group gives them (#334): the widest role and visibility win, and the areas and
     * path prefixes add up, so a grant without a limit lifts the limit.
     *
     * ponytail: the union is taken per axis, so an area-only grant next to a path-only grant on
     * the same scope reads as both axes combined. Grants with both limits on one scope are rare;
     * if they become common, carry a list of (areas, prefixes) windows in PerimeterData instead.
     *
     * @param  Collection<int, Grant>  $grants  Active grants of the principal covering the scope.
     *
     * @throws InvalidArgumentException When the principal is unusable or a grant is not theirs or expired.
     */
    public function fromGrants(Principal $principal, Collection $grants, ScopeTypeEnum $scopeType, string $scopeId): PerimeterData
    {
        $this->assertPrincipalUsable($principal);

        if ($grants->isEmpty()) {
            throw new InvalidArgumentException('No grant covers the scope.');
        }

        foreach ($grants as $grant) {
            if ($grant->principal_id !== $principal->id) {
                throw new InvalidArgumentException('Grant does not belong to the principal.');
            }

            if ($grant->expires_at !== null && $grant->expires_at->isPast()) {
                throw new InvalidArgumentException('Grant is expired.');
            }
        }

        /** @var Grant $widestRole */
        $widestRole = $grants->sortByDesc(static fn (Grant $grant): int => self::ROLE_RANK[$grant->role->value])->first();
        /** @var Grant $widestVisibility */
        $widestVisibility = $grants->sortByDesc(static fn (Grant $grant): int => self::VISIBILITY_RANK[$grant->max_visibility->value])->first();
        $isAreaUnlimited = $grants->contains(static fn (Grant $grant): bool => $grant->areas === []);
        $isPathUnlimited = $grants->contains(static fn (Grant $grant): bool => $grant->path_prefixes === []);

        return new PerimeterData(
            principalId: $principal->id,
            principalType: $principal->type,
            scopeType: $scopeType,
            scopeId: $scopeId,
            role: $widestRole->role,
            maxVisibility: $widestVisibility->max_visibility,
            pathPrefixes: $isPathUnlimited ? [] : array_values(array_unique($grants->flatMap(static fn (Grant $grant): array => $grant->path_prefixes)->all())),
            areas: $isAreaUnlimited ? [] : array_values(array_unique($grants->flatMap(static fn (Grant $grant): array => $grant->areas)->all())),
        );
    }

    /**
     * The areas both lists allow, where an empty list allows every area. Null when nothing is
     * left: the narrowing asked only for areas the grant does not reach.
     *
     * @param  list<string>  $grantedAreas
     * @param  list<string>  $requestedAreas
     * @return list<string>|null
     */
    public static function narrowAreas(array $grantedAreas, array $requestedAreas): ?array
    {
        if ($requestedAreas === []) {
            return $grantedAreas;
        }

        if ($grantedAreas === []) {
            return $requestedAreas;
        }

        $areas = array_values(array_intersect($requestedAreas, $grantedAreas));

        return $areas === [] ? null : $areas;
    }

    /**
     * The folders both lists allow, where an empty list allows every path: a requested folder
     * inside a granted one stays, a granted folder inside a requested one stays. Null when nothing
     * is left.
     *
     * @param  list<string>  $grantedPathPrefixes
     * @param  list<string>  $requestedPathPrefixes
     * @return list<string>|null
     */
    public static function narrowPathPrefixes(array $grantedPathPrefixes, array $requestedPathPrefixes): ?array
    {
        if ($requestedPathPrefixes === []) {
            return $grantedPathPrefixes;
        }

        if ($grantedPathPrefixes === []) {
            return $requestedPathPrefixes;
        }

        $isInside = static fn (string $path, string $folder): bool => $path === $folder || str_starts_with($path, $folder.'/');
        $pathPrefixes = [];

        foreach ($requestedPathPrefixes as $requested) {
            foreach ($grantedPathPrefixes as $granted) {
                if ($isInside($requested, $granted)) {
                    $pathPrefixes[] = $requested;
                } elseif ($isInside($granted, $requested)) {
                    $pathPrefixes[] = $granted;
                }
            }
        }

        $pathPrefixes = array_values(array_unique($pathPrefixes));

        return $pathPrefixes === [] ? null : $pathPrefixes;
    }

    private function assertPrincipalUsable(Principal $principal): void
    {
        if ($principal->deactivated_at !== null) {
            throw new InvalidArgumentException('Principal is deactivated.');
        }

        if ($principal->expires_at !== null && $principal->expires_at->isPast()) {
            throw new InvalidArgumentException('Principal is expired.');
        }

        if ($principal->owner_principal_id !== null) {
            $owner = $principal->ownerPrincipal;
            if ($owner === null) {
                throw new InvalidArgumentException('Owner principal is missing.');
            }

            if ($owner->deactivated_at !== null || ($owner->expires_at !== null && $owner->expires_at->isPast())) {
                throw new InvalidArgumentException('Owner principal is not usable; owned keys and agents stop working.');
            }
        }
    }

    /**
     * Every active grant covering the scope: the ones on the scope itself and, for a company or
     * product, the owner grants on the organisation it belongs to, which cover everything under it.
     * They add up ({@see fromGrants()}).
     *
     * @return Collection<int, Grant>
     */
    public function grantsCovering(Principal $principal, ScopeTypeEnum $scopeType, string $scopeId): Collection
    {
        /** @var Collection<int, Grant> $grants */
        $grants = $principal->grants()
            ->active()
            ->where('scope_type', $scopeType->value)
            ->where('scope_id', $scopeId)
            ->get();

        if ($scopeType === ScopeTypeEnum::Organisation) {
            return $grants;
        }

        $organisationId = $scopeType === ScopeTypeEnum::Company
            ? Company::query()->whereKey($scopeId)->value('organisation_id')
            : Product::query()->whereKey($scopeId)->with('company')->first()?->company?->organisation_id;

        if (! is_string($organisationId) || $organisationId === '') {
            return $grants;
        }

        /** @var Collection<int, Grant> $organisationOwnerGrants */
        $organisationOwnerGrants = $principal->grants()
            ->active()
            ->where('scope_type', ScopeTypeEnum::Organisation->value)
            ->where('scope_id', $organisationId)
            ->where('role', RoleEnum::Owner->value)
            ->get();

        return $grants->concat($organisationOwnerGrants)->values();
    }

    private function roleNotWider(RoleEnum $keyRole, RoleEnum $grantRole): bool
    {
        return self::ROLE_RANK[$keyRole->value] <= self::ROLE_RANK[$grantRole->value];
    }

    private function visibilityNotWider(VisibilityEnum $keyVisibility, VisibilityEnum $grantVisibility): bool
    {
        return self::VISIBILITY_RANK[$keyVisibility->value] <= self::VISIBILITY_RANK[$grantVisibility->value];
    }
}
