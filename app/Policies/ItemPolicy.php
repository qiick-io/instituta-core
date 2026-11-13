<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

/**
 * Brain item access for signed-in users: any principal may list; record view
 * requires a principal in the item's organisation (perimeter still scopes reads).
 */
class ItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->principals()->exists();
    }

    public function view(User $user, Item $item): bool
    {
        return $user->principals()
            ->where('organisation_id', $item->organisation_id)
            ->exists();
    }
}
