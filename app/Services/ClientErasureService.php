<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\RevokeApiKeyAction;
use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Enums\CompanyKindEnum;
use App\Enums\OffboardingStatusEnum;
use App\Models\AgentSession;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\ContractCall;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemChange;
use App\Models\OffboardingRequest;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Erases a client company subtree after export (case 8): index, replay, keys.
 */
final class ClientErasureService
{
    public function __construct(
        private readonly ActivityEventLoggerService $activity,
        private readonly RevokeApiKeyAction $revokeApiKey,
        private readonly PrincipalLifecycleService $principals,
    ) {}

    /**
     * @return array{request: OffboardingRequest, deleted_items: int, revoked_keys: int}
     *
     * @throws InvalidArgumentException|Throwable
     */
    public function erase(OffboardingRequest $request, ?User $causer = null): array
    {
        $company = $request->company;

        if (! $company instanceof Company) {
            $company = Company::query()->findOrFail($request->company_id);
        }

        if ($company->kind !== CompanyKindEnum::Client) {
            throw new InvalidArgumentException('Only client companies can be erased.');
        }

        if ($request->status !== OffboardingStatusEnum::Exported) {
            throw new InvalidArgumentException('Offboarding request must be exported before erase.');
        }

        $productIds = Product::query()
            ->where('company_id', $company->id)
            ->pluck('id')
            ->all();

        /** @var list<string> $productIds */
        $productIds = array_values($productIds);

        $deletedItems = 0;
        $revokedKeys = 0;

        DB::transaction(function () use (
            $request,
            $company,
            $productIds,
            $causer,
            &$deletedItems,
            &$revokedKeys,
        ): void {
            $this->deleteContractCalls($company->id, $productIds);
            $this->invalidateAgentSessions($company->id, $productIds);
            $revokedKeys = $this->revokeScopedKeys($company->id, $productIds);
            $this->deactivateScopedAgentPrincipals($company->organisation_id, $company->id, $productIds);

            $itemQuery = Item::query()->where(function ($query) use ($company, $productIds): void {
                $query->where('company_id', $company->id);

                if ($productIds !== []) {
                    $query->orWhereIn('product_id', $productIds);
                }
            });

            $deletedItems = (clone $itemQuery)->count();
            $itemQuery->delete();

            // The change log (#228) names erased items too: it goes with them.
            ItemChange::query()
                ->where(function ($query) use ($company, $productIds): void {
                    $query->where('company_id', $company->id);

                    if ($productIds !== []) {
                        $query->orWhereIn('product_id', $productIds);
                    }
                })
                ->delete();

            Source::query()
                ->where(function ($query) use ($company, $productIds): void {
                    $query->where('company_id', $company->id);

                    if ($productIds !== []) {
                        $query->orWhereIn('product_id', $productIds);
                    }
                })
                ->delete();

            // Logos (#257) are the client's data too.
            $logos = app(LogoStorageService::class);
            $logos->delete($company->logo_path);
            $company->forceFill(['logo_path' => null])->save();

            foreach (Product::query()->whereIn('id', $productIds)->whereNotNull('logo_path')->get() as $product) {
                $logos->delete($product->logo_path);
                $product->forceFill(['logo_path' => null])->save();
            }

            $request->forceFill([
                'status' => OffboardingStatusEnum::Erased,
                'erased_at' => now(),
            ])->save();

            $this->activity->offboardingErased($company, $request, $causer, [
                'deleted_items' => $deletedItems,
                'revoked_keys' => $revokedKeys,
            ]);
        });

        return [
            'request' => $request->fresh() ?? $request,
            'deleted_items' => $deletedItems,
            'revoked_keys' => $revokedKeys,
        ];
    }

    /**
     * @param  list<string>  $productIds
     */
    private function deleteContractCalls(string $companyId, array $productIds): void
    {
        ContractCall::query()
            ->where(function ($query) use ($companyId, $productIds): void {
                $query->where('company_id', $companyId);

                if ($productIds !== []) {
                    $query->orWhereIn('product_id', $productIds);
                }

                $query->orWhere(function ($scopeQuery) use ($companyId): void {
                    $scopeQuery->where('scope_type', ScopeTypeEnum::Company->value)
                        ->where('scope_id', $companyId);
                });

                if ($productIds !== []) {
                    $query->orWhere(function ($scopeQuery) use ($productIds): void {
                        $scopeQuery->where('scope_type', ScopeTypeEnum::Product->value)
                            ->whereIn('scope_id', $productIds);
                    });
                }
            })
            ->delete();
    }

    /**
     * @param  list<string>  $productIds
     */
    private function invalidateAgentSessions(string $companyId, array $productIds): void
    {
        AgentSession::query()
            ->whereNull('invalidated_at')
            ->where(function ($query) use ($companyId, $productIds): void {
                $query->where(function ($scopeQuery) use ($companyId): void {
                    $scopeQuery->where('scope_type', ScopeTypeEnum::Company->value)
                        ->where('scope_id', $companyId);
                });

                if ($productIds !== []) {
                    $query->orWhere(function ($scopeQuery) use ($productIds): void {
                        $scopeQuery->where('scope_type', ScopeTypeEnum::Product->value)
                            ->whereIn('scope_id', $productIds);
                    });
                }
            })
            ->update(['invalidated_at' => now()]);
    }

    /**
     * @param  list<string>  $productIds
     */
    private function revokeScopedKeys(string $companyId, array $productIds): int
    {
        $keys = ApiKey::query()
            ->active()
            ->where(function ($query) use ($companyId, $productIds): void {
                $query->where(function ($scopeQuery) use ($companyId): void {
                    $scopeQuery->where('scope_type', ScopeTypeEnum::Company->value)
                        ->where('scope_id', $companyId);
                });

                if ($productIds !== []) {
                    $query->orWhere(function ($scopeQuery) use ($productIds): void {
                        $scopeQuery->where('scope_type', ScopeTypeEnum::Product->value)
                            ->whereIn('scope_id', $productIds);
                    });
                }
            })
            ->get();

        foreach ($keys as $key) {
            $this->revokeApiKey->execute($key);
        }

        return $keys->count();
    }

    /**
     * Agent principals whose only remaining grants are for this client subtree.
     *
     * @param  list<string>  $productIds
     */
    private function deactivateScopedAgentPrincipals(
        string $organisationId,
        string $companyId,
        array $productIds,
    ): void {
        $agents = Principal::query()
            ->active()
            ->where('organisation_id', $organisationId)
            ->where('type', PrincipalTypeEnum::Agent->value)
            ->with('grants')
            ->get();

        foreach ($agents as $agent) {
            $grants = $agent->grants;
            $keys = $agent->apiKeys()->get();

            $isGrantScopedOnly = $grants->isNotEmpty() && $grants->every(
                fn (Grant $grant): bool => $this->isClientSubtreeScope(
                    $grant->scope_type,
                    $grant->scope_id,
                    $companyId,
                    $productIds,
                ),
            );

            $isKeyScopedOnly = $keys->every(
                fn (ApiKey $key): bool => $this->isClientSubtreeScope(
                    $key->scope_type,
                    $key->scope_id,
                    $companyId,
                    $productIds,
                ),
            );

            if ($isGrantScopedOnly && $isKeyScopedOnly) {
                $this->principals->deactivate($agent);
            }
        }
    }

    /**
     * @param  list<string>  $productIds
     */
    private function isClientSubtreeScope(
        ScopeTypeEnum $scopeType,
        string $scopeId,
        string $companyId,
        array $productIds,
    ): bool {
        if ($scopeType === ScopeTypeEnum::Company && $scopeId === $companyId) {
            return true;
        }

        return $scopeType === ScopeTypeEnum::Product
            && in_array($scopeId, $productIds, true);
    }
}
