<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Principal;
use Illuminate\Support\Facades\DB;

/**
 * Deactivation / expiry cascades: owned agent principals and keys stop working.
 */
final class PrincipalLifecycleService
{
    public function deactivate(Principal $principal): Principal
    {
        return DB::transaction(function () use ($principal): Principal {
            $now = now();
            $principal->forceFill(['deactivated_at' => $now])->save();

            Principal::query()
                ->where('owner_principal_id', $principal->id)
                ->whereNull('deactivated_at')
                ->update(['deactivated_at' => $now]);

            $ownedIds = Principal::query()
                ->where('owner_principal_id', $principal->id)
                ->pluck('id')
                ->all();

            ApiKey::query()
                ->whereIn('principal_id', [$principal->id, ...$ownedIds])
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);

            return $principal->refresh();
        });
    }
}
