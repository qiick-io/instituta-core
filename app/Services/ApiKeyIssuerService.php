<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyAbilityEnum;
use App\Models\ApiKey;
use App\Models\Principal;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates, lists and revokes API keys. Plain secret is shown once.
 */
final class ApiKeyIssuerService
{
    public function __construct(
        private readonly PerimeterResolverService $perimeters = new PerimeterResolverService,
        private readonly BrainWriteGateService $writeGate = new BrainWriteGateService,
    ) {}

    /**
     * @param  list<string>  $abilities
     * @param  list<string>|null  $ipAllowlist
     * @return array{key: ApiKey, plain: string}
     */
    public function create(
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

        $secret = $this->makeSecret();

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

    /**
     * @return Collection<int, ApiKey>
     */
    public function listForPrincipal(Principal $principal): Collection
    {
        return $principal->apiKeys()->orderByDesc('created_at')->get();
    }

    public function revoke(ApiKey $key): ApiKey
    {
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now()])->save();

            $key->loadMissing('principal.user');
            activity('keys')
                ->performedOn($key)
                ->causedBy($key->principal?->user)
                ->event('api_key.revoked')
                ->withProperties([
                    'organisation_id' => $key->principal?->organisation_id,
                    'principal_id' => $key->principal_id,
                ])
                ->log('api_key.revoked');
        }

        return $key;
    }

    public function findByPlainSecret(string $plain): ?ApiKey
    {
        /** @var ApiKey|null $key */
        $key = ApiKey::query()
            ->where('hash', hash('sha256', $plain))
            ->first();

        return $key;
    }

    /**
     * @return array{plain: string, display_prefix: string, hash: string}
     */
    public function makeSecret(string $prefix = ApiKey::DEFAULT_PREFIX): array
    {
        $plain = $prefix.Str::random(40);
        $displayPrefix = substr($plain, 0, 12);

        return [
            'plain' => $plain,
            'display_prefix' => $displayPrefix,
            'hash' => hash('sha256', $plain),
        ];
    }
}
