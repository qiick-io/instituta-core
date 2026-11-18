<?php

declare(strict_types=1);

namespace App\Brain\Data;

use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;

/**
 * Resolved once per request. A session or API key is bound to exactly one scope.
 *
 * Inside the scope, `areas` and `pathPrefixes` limit what can be read (#334): an empty list means
 * no limit on that axis. Every surface asks {@see allowsArea()}, {@see allowsPath()} or
 * {@see allowsLocation()} (or the `withinPerimeter` query scope, which applies the same rule), so
 * one place decides what an area limit means.
 */
final readonly class PerimeterData
{
    /**
     * @param  list<string>  $areas  Allowed areas; empty: every area.
     * @param  list<string>  $pathPrefixes  Allowed folders (without slashes at either end); empty: every path.
     */
    public function __construct(
        public string $principalId,
        public PrincipalTypeEnum $principalType,
        public ScopeTypeEnum $scopeType,
        public string $scopeId,
        public RoleEnum $role,
        public VisibilityEnum $maxVisibility,
        public array $pathPrefixes = [],
        public array $areas = [],
    ) {}

    /**
     * Whether an area or path limit narrows this perimeter inside its scope.
     */
    public function isLimited(): bool
    {
        return $this->areas !== [] || $this->pathPrefixes !== [];
    }

    /**
     * An item without an area is refused under an area limit: the limit fails closed.
     */
    public function allowsArea(?string $area): bool
    {
        return self::limitsAllow($this->areas, [], $area, '');
    }

    public function allowsPath(string $path): bool
    {
        return self::limitsAllow([], $this->pathPrefixes, null, $path);
    }

    /**
     * Whether an item at this place (area and path) is inside the limits; visibility and scope
     * are checked on their own.
     */
    public function allowsLocation(?string $area, string $path): bool
    {
        return self::limitsAllow($this->areas, $this->pathPrefixes, $area, $path);
    }

    /**
     * The one rule for area and path limits, also for a single grant's limits.
     *
     * @param  list<string>  $areas  Empty: every area.
     * @param  list<string>  $pathPrefixes  Empty: every path.
     */
    public static function limitsAllow(array $areas, array $pathPrefixes, ?string $area, string $path): bool
    {
        if ($areas !== [] && ($area === null || ! in_array($area, $areas, true))) {
            return false;
        }

        if ($pathPrefixes === []) {
            return true;
        }

        $normalisedPath = self::normalisePath($path);

        foreach ($pathPrefixes as $pathPrefix) {
            if ($normalisedPath === $pathPrefix || str_starts_with($normalisedPath, $pathPrefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same principal, role and limits on another scope: a resolution layer, or a child scope
     * an organisation owner reads. The caller has checked the scope is covered.
     */
    public function forScope(ScopeTypeEnum $scopeType, string $scopeId): self
    {
        return new self(
            principalId: $this->principalId,
            principalType: $this->principalType,
            scopeType: $scopeType,
            scopeId: $scopeId,
            role: $this->role,
            maxVisibility: $this->maxVisibility,
            pathPrefixes: $this->pathPrefixes,
            areas: $this->areas,
        );
    }

    /**
     * A path or prefix as limits compare them: forward slashes, none at either end.
     */
    public static function normalisePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @return array{
     *     principal_id: string,
     *     principal_type: string,
     *     scope_type: string,
     *     scope_id: string,
     *     role: string,
     *     max_visibility: string,
     *     path_prefixes: list<string>,
     *     areas: list<string>
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
            'path_prefixes' => $this->pathPrefixes,
            'areas' => $this->areas,
        ];
    }
}
