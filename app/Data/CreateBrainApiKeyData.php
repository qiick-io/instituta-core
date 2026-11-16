<?php

declare(strict_types=1);

namespace App\Data;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\VisibilityEnum;
use Carbon\CarbonImmutable;

/**
 * An API key as the Keys page submits it (#201). With `agentName` the key belongs to a new
 * agent principal owned by the signed-in person; without it, to the person's own principal.
 */
readonly class CreateBrainApiKeyData
{
    public function __construct(
        public string $name,
        public ?string $agentName,
        public RoleEnum $role,
        public VisibilityEnum $maxVisibility,
        public bool $canWrite,
        public ?CarbonImmutable $expiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $agentName = isset($validated['agent_name']) && is_string($validated['agent_name']) && trim($validated['agent_name']) !== ''
            ? trim($validated['agent_name'])
            : null;

        return new self(
            name: trim((string) $validated['name']),
            agentName: $validated['principal'] === 'agent' ? $agentName : null,
            role: RoleEnum::from((string) $validated['role']),
            maxVisibility: VisibilityEnum::from((string) $validated['max_visibility']),
            canWrite: (bool) ($validated['can_write'] ?? false),
            expiresAt: isset($validated['expires_at']) && is_string($validated['expires_at']) && $validated['expires_at'] !== ''
                ? CarbonImmutable::parse($validated['expires_at'])->endOfDay()
                : null,
        );
    }
}
