<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Enums\RoleEnum;
use App\Enums\ApiKeyAbilityEnum;
use App\Enums\ContractErrorCodeEnum;
use App\Exceptions\ContractException;
use App\Models\ApiKey;
use App\Models\Principal;

/**
 * Kill switch + ability + role checks for brain:write (#54).
 *
 * Writes stay off unless the surface flag is on, the key carries brain:write,
 * and the key (and principal) role is owner or member — never guest.
 */
final class BrainWriteGateService
{
    /**
     * Whether write is enabled for the HTTP MCP surface.
     */
    public function isMcpWriteEnabled(): bool
    {
        return (bool) config('instituta.mcp.write_enabled', false);
    }

    /**
     * Whether write is enabled for the REST surface.
     */
    public function isApiWriteEnabled(): bool
    {
        return (bool) config('instituta.api.write_enabled', false);
    }

    /**
     * Whether a principal may hold brain:write on a key (owner/member only).
     */
    public function principalMayHoldWriteAbility(Principal $principal): bool
    {
        return $principal->grants()
            ->whereIn('role', [RoleEnum::Owner->value, RoleEnum::Member->value])
            ->exists();
    }

    /**
     * Whether an API key may perform a write on the given surface.
     */
    public function keyMayWrite(ApiKey $key, string $surface): bool
    {
        $isEnabled = match ($surface) {
            'mcp' => $this->isMcpWriteEnabled(),
            'api' => $this->isApiWriteEnabled(),
            default => false,
        };

        if (! $isEnabled) {
            return false;
        }

        if (! in_array(ApiKeyAbilityEnum::BrainWrite->value, $key->abilities, true)) {
            return false;
        }

        if (! in_array($key->role, [RoleEnum::Owner, RoleEnum::Member], true)) {
            return false;
        }

        return true;
    }

    /**
     * The refusal with its contract code (#227), or null when the key may write.
     */
    public function refusal(ApiKey $key, string $surface): ?ContractException
    {
        $isEnabled = match ($surface) {
            'mcp' => $this->isMcpWriteEnabled(),
            'api' => $this->isApiWriteEnabled(),
            default => false,
        };

        if (! $isEnabled) {
            return new ContractException(ContractErrorCodeEnum::WriteDisabled, 'Brain writes are disabled.');
        }

        if (! in_array(ApiKeyAbilityEnum::BrainWrite->value, $key->abilities, true)) {
            return new ContractException(ContractErrorCodeEnum::AbilityMissing, 'Ability brain:write is required.');
        }

        if (! in_array($key->role, [RoleEnum::Owner, RoleEnum::Member], true)) {
            return new ContractException(ContractErrorCodeEnum::GuestRefused, 'Guest keys cannot write.');
        }

        return null;
    }
}
