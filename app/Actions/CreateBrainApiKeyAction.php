<?php

declare(strict_types=1);

namespace App\Actions;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Data\CreateBrainApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\Grant;
use App\Models\Principal;
use App\Models\User;
use App\Services\PerimeterResolverService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issues an API key from the Keys page (#201), in the owner's current scope.
 *
 * A key for "me" belongs to the owner's person principal. A key for an agent creates the agent
 * principal (owned by the person) and its grant in the same scope first, so the key's
 * perimeter never exceeds what the owner granted. Everything else — write scope rules,
 * hashing, the one-time secret — is {@see CreateApiKeyAction}, the same as the CLI.
 *
 * @throws InvalidArgumentException When the key would exceed the owner's role or writes are off.
 */
final class CreateBrainApiKeyAction
{
    public function __construct(
        private readonly CreateApiKeyAction $createApiKey,
    ) {}

    /**
     * @return array{key: ApiKey, plain: string}
     */
    public function execute(Principal $owner, PerimeterData $perimeter, CreateBrainApiKeyData $data, ?User $createdBy = null): array
    {
        if ($perimeter->role !== RoleEnum::Owner) {
            throw new InvalidArgumentException('Only owners can create API keys.');
        }

        return DB::transaction(function () use ($owner, $perimeter, $data, $createdBy): array {
            $principal = $owner;

            if ($data->agentName !== null) {
                // The agent's grant never reaches past the owner's areas and folders (#334).
                $agentAreas = PerimeterResolverService::narrowAreas($perimeter->areas, $data->areas);
                $agentPathPrefixes = PerimeterResolverService::narrowPathPrefixes($perimeter->pathPrefixes, $data->pathPrefixes);

                if ($agentAreas === null || $agentPathPrefixes === null) {
                    throw new InvalidArgumentException('The key\'s areas or folders are outside your access.');
                }

                $principal = Principal::query()->create([
                    'organisation_id' => $owner->organisation_id,
                    'type' => PrincipalTypeEnum::Agent,
                    'owner_principal_id' => $owner->id,
                    'name' => $data->agentName,
                ]);

                Grant::query()->create([
                    'owner_principal_id' => $principal->id,
                    'scope_type' => $perimeter->scopeType,
                    'scope_id' => $perimeter->scopeId,
                    'role' => $data->role,
                    'max_visibility' => $data->maxVisibility,
                    'areas' => $agentAreas,
                    'path_prefixes' => $agentPathPrefixes,
                ]);
            }

            $scopes = $data->canWrite
                ? [ApiKeyScopeEnum::BrainWrite->value]
                : [ApiKeyScopeEnum::BrainRead->value];

            return $this->createApiKey->execute(
                principal: $principal,
                name: $data->name,
                scopeType: $perimeter->scopeType,
                scopeId: $perimeter->scopeId,
                role: $data->role,
                maxVisibility: $data->maxVisibility,
                scopes: $scopes,
                expiresAt: $data->expiresAt,
                createdBy: $createdBy,
                areas: $data->areas,
                pathPrefixes: $data->pathPrefixes,
            );
        });
    }
}
