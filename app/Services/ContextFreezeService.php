<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContextFreeze;
use App\Models\Principal;
use App\Models\VaultVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Emergency freeze / unfreeze against a signed vault version (Artisan only in MVP).
 */
final class ContextFreezeService
{
    public function freeze(
        VaultVersion $vault,
        Principal $principal,
        string $reason,
        ?string $companyId = null,
        ?string $productId = null,
    ): ContextFreeze {
        if ($vault->organisation_id !== $principal->organisation_id) {
            throw new InvalidArgumentException('Vault version is outside the principal organisation.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Freeze reason is required.');
        }

        return DB::transaction(function () use ($vault, $principal, $reason, $companyId, $productId): ContextFreeze {
            ContextFreeze::query()
                ->active()
                ->where('organisation_id', $vault->organisation_id)
                ->when($companyId === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $companyId))
                ->when($productId === null, fn ($q) => $q->whereNull('product_id'), fn ($q) => $q->where('product_id', $productId))
                ->update(['lifted_at' => now()]);

            return ContextFreeze::query()->create([
                'organisation_id' => $vault->organisation_id,
                'company_id' => $companyId,
                'product_id' => $productId,
                'vault_version_id' => $vault->id,
                'reason' => $reason,
                'frozen_by_principal_id' => $principal->id,
                'frozen_at' => now(),
                'lifted_at' => null,
            ]);
        });
    }

    public function unfreeze(ContextFreeze $freeze): ContextFreeze
    {
        if ($freeze->lifted_at !== null) {
            return $freeze;
        }

        $freeze->forceFill(['lifted_at' => now()])->save();

        return $freeze->refresh();
    }
}
