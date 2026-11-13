<?php

declare(strict_types=1);

namespace App\Actions;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyAbilityEnum;
use App\Models\ApiKey;
use App\Models\Principal;
use App\Services\ApiKeyIssuerService;
use App\Services\BrainWriteGateService;
use App\Services\PerimeterResolverService;
use InvalidArgumentException;

/**
 * Issues a new API key for a principal (plain secret returned once).
 */
final class CreateApiKeyAction
{
    public function __construct(
        private readonly ApiKeyIssuerService $issuer,
        private readonly PerimeterResolverService $perimeters = new PerimeterResolverService,
        private readonly BrainWriteGateService $writeGate = new BrainWriteGateService,
    ) {}

    /**
     * @param  list<string>  $abilities
     * @param  list<string>|null  $ipAllowlist
     * @return array{key: ApiKey, plain: string}
     */
    public function execute(
        Principal $principal,
        string $name,
        ScopeTypeEnum $scopeType,
        string $scopeId,
        RoleEnum $role,
        VisibilityEnum $maxVisibility,
        array $abilities = [ApiKeyAbilityEnum::BrainRead->value],
        ?array $ipAllowlist = null,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        if (in_array(ApiKeyAbilityEnum::BrainWrite->value, $abilities, true)) {
            if (! $this->writeGate->isApiWriteEnabled() && ! $this->writeGate->isMcpWriteEnabled()) {
                throw new InvalidArgumentException('Ability brain:write is refused while writes are disabled.');
            }

            if (! in_array($role, [RoleEnum::Owner, RoleEnum::Member], true)) {
                throw new InvalidArgumentException('Guest keys cannot hold brain:write.');
            }

            if (! $this->writeGate->principalMayHoldWriteAbility($principal)) {
                throw new InvalidArgumentException('Principal has no owner/member grant for brain:write.');
            }
        }

        if ($abilities === []) {
            $abilities = [ApiKeyAbilityEnum::BrainRead->value];
        }

        if (
            in_array(ApiKeyAbilityEnum::BrainWrite->value, $abilities, true)
            && ! in_array(ApiKeyAbilityEnum::BrainRead->value, $abilities, true)
        ) {
            $abilities[] = ApiKeyAbilityEnum::BrainRead->value;
        }

        $secret = $this->issuer->makeSecret();

        $key = new ApiKey([
            'name' => $name,
            'prefix' => ApiKey::DEFAULT_PREFIX,
            'hash' => $secret['hash'],
            'display_prefix' => $secret['display_prefix'],
            'principal_id' => $principal->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'role' => $role,
            'max_visibility' => $maxVisibility,
            'abilities' => $abilities,
            'ip_allowlist' => $ipAllowlist,
            'expires_at' => $expiresAt,
        ]);

        // Validate perimeter before persisting.
        $this->perimeters->fromApiKey($key->setRelation('principal', $principal));
        $key->save();

        activity('keys')
            ->performedOn($key)
            ->causedBy($principal->user)
            ->event('api_key.created')
            ->withProperties([
                'organisation_id' => $principal->organisation_id,
                'principal_id' => $principal->id,
                'scope_type' => $scopeType->value,
                'scope_id' => $scopeId,
                'role' => $role->value,
            ])
            ->log('api_key.created');

        return ['key' => $key, 'plain' => $secret['plain']];
    }
}
