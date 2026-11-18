<?php

declare(strict_types=1);

namespace App\Actions;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\Principal;
use App\Models\User;
use App\Services\ApiKeyIssuerService;
use App\Services\BrainWriteGateService;
use App\Services\PerimeterResolverService;
use InvalidArgumentException;

/**
 * Issues a new API key for a principal (plain secret returned once).
 *
 * Empty scopes are refused. `brain:write` requires writes enabled and an
 * owner/member principal. The plaintext never enters the activity log.
 */
final class CreateApiKeyAction
{
    public function __construct(
        private readonly ApiKeyIssuerService $issuer,
        private readonly PerimeterResolverService $perimeters = new PerimeterResolverService,
        private readonly BrainWriteGateService $writeGate = new BrainWriteGateService,
    ) {}

    /**
     * @param  list<string>  $scopes
     * @param  list<string>|null  $ipAllowlist
     * @param  list<string>  $areas  Narrows the grant's areas (#334); empty: the grant's.
     * @param  list<string>  $pathPrefixes  Narrows the grant's folders (#334); empty: the grant's.
     * @return array{key: ApiKey, plain: string}
     */
    public function execute(
        Principal $principal,
        string $name,
        ScopeTypeEnum $scopeType,
        string $scopeId,
        RoleEnum $role,
        VisibilityEnum $maxVisibility,
        array $scopes = [ApiKeyScopeEnum::BrainRead->value],
        ?array $ipAllowlist = null,
        ?int $rateLimitPerMinute = null,
        ?\DateTimeInterface $expiresAt = null,
        ?User $createdBy = null,
        array $areas = [],
        array $pathPrefixes = [],
    ): array {
        $scopes = ApiKeyScopeEnum::normalize($scopes);

        if ($scopes === []) {
            throw new InvalidArgumentException('API key scopes must not be empty.');
        }

        if (in_array(ApiKeyScopeEnum::BrainWrite->value, $scopes, true)) {
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

        $secret = $this->issuer->makeSecret();

        $key = new ApiKey([
            'name' => $name,
            'key_prefix' => $secret['key_prefix'],
            'key_hash' => $secret['hash'],
            'principal_id' => $principal->id,
            'created_by' => $createdBy !== null ? $createdBy->id : $principal->user_id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'role' => $role,
            'max_visibility' => $maxVisibility,
            'areas' => $areas,
            'path_prefixes' => $pathPrefixes,
            'scopes' => $scopes,
            'ip_allowlist' => $ipAllowlist,
            'rate_limit_per_minute' => $rateLimitPerMinute,
            'expires_at' => $expiresAt,
        ]);

        // Validate perimeter before persisting: role, visibility, areas and folders within the grants.
        $this->perimeters->fromApiKey($key->setRelation('principal', $principal));
        $key->save();

        activity('api-keys')
            ->performedOn($key)
            ->causedBy($createdBy ?? $principal->user)
            ->event('created')
            ->withProperties([
                'name' => $key->name,
                'scopes' => $key->scopes,
                'key_prefix' => $key->key_prefix,
                'organisation_id' => $principal->organisation_id,
                'principal_id' => $principal->id,
                'scope_type' => $scopeType->value,
                'scope_id' => $scopeId,
                'role' => $role->value,
                'areas' => $key->areas,
                'path_prefixes' => $key->path_prefixes,
            ])
            ->log('created');

        return ['key' => $key, 'plain' => $secret['plain']];
    }
}
