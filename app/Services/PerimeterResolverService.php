<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\ApiKey;
use App\Models\Grant;
use App\Models\Principal;
use InvalidArgumentException;

/**
 * Resolves the single request perimeter from a key or principal grant.
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

        $grant = $this->matchingGrant($principal, $key->scope_type, $key->scope_id);
        if ($grant === null) {
            throw new InvalidArgumentException('API key perimeter is not covered by a grant.');
        }

        if (! $this->roleNotWider($key->role, $grant->role)) {
            throw new InvalidArgumentException('API key role is wider than the principal grant.');
        }

        if (! $this->visibilityNotWider($key->max_visibility, $grant->max_visibility)) {
            throw new InvalidArgumentException('API key visibility is wider than the principal grant.');
        }

        return new PerimeterData(
            principalId: $principal->id,
            principalType: $principal->type,
            scopeType: $key->scope_type,
            scopeId: $key->scope_id,
            role: $key->role,
            maxVisibility: $key->max_visibility,
            pathPrefix: $grant->path_prefix,
            area: $grant->area,
        );
    }

    public function fromGrant(Principal $principal, Grant $grant): PerimeterData
    {
        $this->assertPrincipalUsable($principal);

        if ($grant->principal_id !== $principal->id) {
            throw new InvalidArgumentException('Grant does not belong to the principal.');
        }

        if ($grant->expires_at !== null && $grant->expires_at->isPast()) {
            throw new InvalidArgumentException('Grant is expired.');
        }

        return new PerimeterData(
            principalId: $principal->id,
            principalType: $principal->type,
            scopeType: $grant->scope_type,
            scopeId: $grant->scope_id,
            role: $grant->role,
            maxVisibility: $grant->max_visibility,
            pathPrefix: $grant->path_prefix,
            area: $grant->area,
        );
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

    private function matchingGrant(Principal $principal, ScopeTypeEnum $scopeType, string $scopeId): ?Grant
    {
        /** @var Grant|null $exact */
        $exact = $principal->grants()
            ->active()
            ->where('scope_type', $scopeType->value)
            ->where('scope_id', $scopeId)
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        if ($scopeType === ScopeTypeEnum::Organisation) {
            return null;
        }

        // Owner grant on the organisation covers company/product scopes under that org.
        /** @var Grant|null $orgOwner */
        $orgOwner = $principal->grants()
            ->active()
            ->where('scope_type', ScopeTypeEnum::Organisation->value)
            ->where('role', RoleEnum::Owner->value)
            ->first();

        return $orgOwner;
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
