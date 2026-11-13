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
                $scopeType = ScopeTypeEnum::from($type);
                $grant = $this->grantCovering($principal, $scopeType, $id);
                if ($grant !== null) {
                    return $this->perimeterForScope($principal, $grant, $scopeType, $id);
                }
            } catch (InvalidArgumentException) {
                // Fall through to the first visible scope.
            }
        }

        $first = $scopes[0];
        $scopeType = ScopeTypeEnum::from((string) $first['scope_type']);
        $scopeId = (string) $first['scope_id'];
        $this->remember($request, $scopeType, $scopeId);

        $grant = $this->grantCovering($principal, $scopeType, $scopeId);
        if ($grant === null) {
            return null;
        }

        return $this->perimeterForScope($principal, $grant, $scopeType, $scopeId);
    }

    public function switchTo(Request $request, User $user, ScopeTypeEnum $scopeType, string $scopeId): PerimeterData
    {
        $principal = $this->personPrincipal($user);
        if ($principal === null) {
            throw new InvalidArgumentException('No active person principal.');
        }

        $grant = $this->grantCovering($principal, $scopeType, $scopeId);
        if ($grant === null) {
            throw new InvalidArgumentException('Scope is outside the principal grants.');
        }

        $perimeter = $this->perimeterForScope($principal, $grant, $scopeType, $scopeId);
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

    private function grantCovering(Principal $principal, ScopeTypeEnum $scopeType, string $scopeId): ?Grant
    {
        /** @var Grant|null $exact */
        $exact = $principal->grants()
            ->active()
            ->where('scope_type', $scopeType->value)
            ->where('scope_id', $scopeId)
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        if ($scopeType === ScopeTypeEnum::Organisation) {
            return null;
        }

        if ($scopeType === ScopeTypeEnum::Company) {
            $organisationId = Company::query()->whereKey($scopeId)->value('organisation_id');
        } else {
            $organisationId = Product::query()
                ->whereKey($scopeId)
                ->with('company')
                ->first()
                ?->company
                ?->organisation_id;
        }

        if (! is_string($organisationId) || $organisationId === '') {
            return null;
        }

        /** @var Grant|null $orgOwner */
        $orgOwner = $principal->grants()
            ->active()
            ->where('scope_type', ScopeTypeEnum::Organisation->value)
            ->where('scope_id', $organisationId)
            ->where('role', 'owner')
            ->first();

        return $orgOwner;
    }

    private function perimeterForScope(
        Principal $principal,
        Grant $grant,
        ScopeTypeEnum $scopeType,
        string $scopeId,
    ): PerimeterData {
        if ($grant->scope_type === $scopeType && $grant->scope_id === $scopeId) {
            return $this->perimeters->fromGrant($principal, $grant);
        }

        // Owner org grant narrowed to a child company/product for the UI session.
        return new PerimeterData(
            principalId: $principal->id,
            principalType: $principal->type,
            scopeType: $scopeType,
            scopeId: $scopeId,
            role: $grant->role,
            maxVisibility: $grant->max_visibility,
            pathPrefix: $grant->path_prefix,
            area: $grant->area,
        );
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
