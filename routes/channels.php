<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Private user channel for database notification broadcasts (Echo).
| Copied from externa-core / app-ui docs/notifications.md.
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, int|string $id): bool {
    return (int) $user->id === (int) $id && $user->is_active;
});
