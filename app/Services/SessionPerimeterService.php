<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\ScopeTypeEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Resolves the UI session perimeter from the signed-in user's person principal.
 */
final class SessionPerimeterService
{
    public const SESSION_SCOPE_TYPE = 'instituta.scope_type';

    public const SESSION_SCOPE_ID = 'instituta.scope_id';

    public function __construct(
        private readonly PerimeterResolverService $perimeters = new PerimeterResolverService,
    ) {}

    public function personPrincipal(User $user): ?Principal
    {
        /** @var Principal|null $principal */
        $principal = $user->principals()
            ->active()
            ->where('type', 'person')
            ->orderBy('id')
            ->first();

        return $principal;
    }

    /**
     * Authenticated person principal + session scope, or null when the UI has no perimeter yet.
     * Aborts 401 when the request has no signed-in user.
     */
    public function optionalPerimeter(Request $request): ?PerimeterData
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $principal = $this->personPrincipal($user);

        if ($principal === null) {
            return null;
        }

        return $this->resolve($request, $principal);
    }

    /**
     * Same as {@see optionalPerimeter()} but aborts 404 when the session has no perimeter.
     */
    public function requirePerimeter(Request $request): PerimeterData
    {
        $perimeter = $this->optionalPerimeter($request);
        abort_unless($perimeter instanceof PerimeterData, 404);

        return $perimeter;
    }

    /**
     * @return array{perimeter: array<string, mixed>|null, scopes: list<array<string, mixed>>, label: string|null}
     */
    public function sharedPayload(Request $request): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return [
                'perimeter' => null,
                'scopes' => [],
                'label' => null,
            ];
        }

        $principal = $this->personPrincipal($user);
        if ($principal === null) {
            return [
                'perimeter' => null,
                'scopes' => [],
                'label' => null,
            ];
        }

        $scopes = $this->visibleScopes($principal);
        $perimeter = $this->resolve($request, $principal, $scopes);

        return [
            'perimeter' => $perimeter?->toArray(),
            'scopes' => $scopes,
            'label' => $perimeter === null ? null : $this->labelFor($perimeter),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $scopes
     */
    public function resolve(Request $request, Principal $principal, array $scopes = []): ?PerimeterData
    {
        if ($scopes === []) {
            $scopes = $this->visibleScopes($principal);
        }

        if ($scopes === []) {
            return null;
        }

        $type = $request->session()->get(self::SESSION_SCOPE_TYPE);
        $id = $request->session()->get(self::SESSION_SCOPE_ID);

        if (is_string($type) && is_string($id) && $type !== '' && $id !== '') {
            try {
                $perimeter = $this->perimeterFor($principal, ScopeTypeEnum::from($type), $id);
                if ($perimeter !== null) {
                    return $perimeter;
                }
            } catch (InvalidArgumentException) {
                // Fall through to the first visible scope.
            }
        }

        $first = $scopes[0];
        $scopeType = ScopeTypeEnum::from((string) $first['scope_type']);
        $scopeId = (string) $first['scope_id'];
        $this->remember($request, $scopeType, $scopeId);

        return $this->perimeterFor($principal, $scopeType, $scopeId);
    }

    public function switchTo(Request $request, User $user, ScopeTypeEnum $scopeType, string $scopeId): PerimeterData
    {
        $principal = $this->personPrincipal($user);
        if ($principal === null) {
            throw new InvalidArgumentException('No active person principal.');
        }

        $perimeter = $this->perimeterFor($principal, $scopeType, $scopeId);
        if ($perimeter === null) {
            throw new InvalidArgumentException('Scope is outside the principal grants.');
        }

        $this->remember($request, $scopeType, $scopeId);

        return $perimeter;
    }

    /**
     * @return list<array{scope_type: string, scope_id: string, label: string, role: string, kind: string|null}>
     */
    public function visibleScopes(Principal $principal): array
    {
        $scopes = [];

        foreach ($principal->grants()->active()->get() as $grant) {
            /** @var Grant $grant */
            $scopes[] = [
                'scope_type' => $grant->scope_type->value,
                'scope_id' => $grant->scope_id,
                'label' => $this->labelForGrant($grant),
                'role' => $grant->role->value,
                'kind' => null,
            ];

            if ($grant->scope_type === ScopeTypeEnum::Organisation && $grant->role->value === 'owner') {
                foreach ($this->childScopesUnderOrganisation($grant->scope_id) as $child) {
                    $scopes[] = $child;
                }
            }
        }

        $unique = [];
        foreach ($scopes as $scope) {
            $key = $scope['scope_type'].':'.$scope['scope_id'];
            $unique[$key] = $scope;
        }

        return array_values($unique);
    }

    private function remember(Request $request, ScopeTypeEnum $scopeType, string $scopeId): void
    {
        $request->session()->put(self::SESSION_SCOPE_TYPE, $scopeType->value);
        $request->session()->put(self::SESSION_SCOPE_ID, $scopeId);
    }

    /**
     * The perimeter every grant covering the scope gives together (#334), or null when none does.
     */
    private function perimeterFor(Principal $principal, ScopeTypeEnum $scopeType, string $scopeId): ?PerimeterData
    {
        $grants = $this->perimeters->grantsCovering($principal, $scopeType, $scopeId);

        return $grants->isEmpty() ? null : $this->perimeters->fromGrants($principal, $grants, $scopeType, $scopeId);
    }

    /**
     * @return list<array{scope_type: string, scope_id: string, label: string, role: string, kind: string|null}>
     */
    private function childScopesUnderOrganisation(string $organisationId): array
    {
        $children = [];

        $companies = Company::query()
            ->where('organisation_id', $organisationId)
            ->with('products')
            ->orderBy('name')
            ->get();
        foreach ($companies as $company) {
            $children[] = [
                'scope_type' => ScopeTypeEnum::Company->value,
                'scope_id' => $company->id,
                'label' => $company->name,
                'role' => 'owner',
                'kind' => $company->kind->value,
            ];

            foreach ($company->products as $product) {
                $children[] = [
                    'scope_type' => ScopeTypeEnum::Product->value,
                    'scope_id' => $product->id,
                    'label' => $company->name.' / '.$product->name,
                    'role' => 'owner',
                    'kind' => $company->kind->value,
                ];
            }
        }

        return $children;
    }

    private function labelForGrant(Grant $grant): string
    {
        return match ($grant->scope_type) {
            ScopeTypeEnum::Organisation => (string) (Organisation::query()->whereKey($grant->scope_id)->value('name') ?? $grant->scope_id),
            ScopeTypeEnum::Company => (string) (Company::query()->whereKey($grant->scope_id)->value('name') ?? $grant->scope_id),
            ScopeTypeEnum::Product => (string) (Product::query()->whereKey($grant->scope_id)->value('name') ?? $grant->scope_id),
        };
    }

    private function labelFor(PerimeterData $perimeter): string
    {
        return match ($perimeter->scopeType) {
            ScopeTypeEnum::Organisation => (string) (Organisation::query()->whereKey($perimeter->scopeId)->value('name') ?? $perimeter->scopeId),
            ScopeTypeEnum::Company => (string) (Company::query()->whereKey($perimeter->scopeId)->value('name') ?? $perimeter->scopeId),
            ScopeTypeEnum::Product => (string) (Product::query()->whereKey($perimeter->scopeId)->value('name') ?? $perimeter->scopeId),
        };
    }
}
