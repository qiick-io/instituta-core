<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\RoleEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\Principal;
use App\Models\User;

/**
 * API keys list for owners: the keys of their person principal and of the agent / service
 * principals they own (#201).
 */
final class BrainKeysService
{
    /**
     * @return array{is_owner: bool, keys: list<array<string, mixed>>}
     */
    public function listFor(User $user): array
    {
        $principal = $user->principals()
            ->active()
            ->where('type', 'person')
            ->orderBy('id')
            ->first();

        if (! $principal instanceof Principal) {
            return ['is_owner' => false, 'keys' => []];
        }

        $isOwner = $principal->grants()
            ->active()
            ->where('role', RoleEnum::Owner->value)
            ->exists();

        if (! $isOwner) {
            return ['is_owner' => false, 'keys' => []];
        }

        $principalIds = [$principal->id, ...$principal->ownedPrincipals()->pluck('id')->all()];

        /** @var list<ApiKey> $keys */
        $keys = ApiKey::query()
            ->with('principal')
            ->whereIn('principal_id', $principalIds)
            ->orderByDesc('created_at')
            ->get()
            ->all();

        $rows = [];

        foreach ($keys as $key) {
            $rows[] = [
                'id' => $key->id,
                'name' => $key->name,
                'principal_name' => $key->principal?->name,
                'principal_type' => $key->principal?->type->value,
                // Derived for the existing screen until registry items replace can_write.
                'can_write' => $key->hasScope(ApiKeyScopeEnum::BrainWrite),
                'display_prefix' => $key->key_prefix,
                'key_prefix' => $key->key_prefix,
                'scopes' => $key->scopes,
                'scope_type' => $key->scope_type->value,
                'scope_id' => $key->scope_id,
                'role' => $key->role->value,
                'max_visibility' => $key->max_visibility->value,
                'areas' => $key->areas,
                'path_prefixes' => $key->path_prefixes,
                'rate_limit_per_minute' => $key->rate_limit_per_minute,
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'last_used_ip' => $key->last_used_ip,
                'expires_at' => $key->expires_at?->toIso8601String(),
                'revoked_at' => $key->revoked_at?->toIso8601String(),
                'revoked' => $key->revoked_at !== null,
            ];
        }

        return ['is_owner' => true, 'keys' => $rows];
    }

    /**
     * The areas an owner can give a key in their current scope (#334): their own when they are
     * limited, otherwise every area of the organisation.
     *
     * @return list<string>
     */
    public function areaOptionsFor(PerimeterData $perimeter): array
    {
        if ($perimeter->areas !== []) {
            return $perimeter->areas;
        }

        $organisationId = app(PerimeterScopeColumnsService::class)->columnsFor($perimeter)[0];

        return $organisationId === null ? [] : app(BrainMapService::class)->areasOf($organisationId);
    }
}
