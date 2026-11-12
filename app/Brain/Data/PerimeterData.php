<?php

declare(strict_types=1);

namespace App\Brain\Data;

use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;

/**
 * Resolved once per request. A session or API key is bound to exactly one scope.
 */
final readonly class PerimeterData
{
    public function __construct(
        public string $principalId,
        public PrincipalTypeEnum $principalType,
        public ScopeTypeEnum $scopeType,
        public string $scopeId,
        public RoleEnum $role,
        public VisibilityEnum $maxVisibility,
        public ?string $pathPrefix = null,
        public ?string $area = null,
    ) {}

    /**
     * @return array{
     *     principal_id: string,
     *     principal_type: string,
     *     scope_type: string,
     *     scope_id: string,
     *     role: string,
     *     max_visibility: string,
     *     path_prefix: string|null,
     *     area: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'principal_id' => $this->principalId,
            'principal_type' => $this->principalType->value,
            'scope_type' => $this->scopeType->value,
            'scope_id' => $this->scopeId,
            'role' => $this->role->value,
            'max_visibility' => $this->maxVisibility->value,
            'path_prefix' => $this->pathPrefix,
            'area' => $this->area,
        ];
    }
}
