<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Membership of a user in a {@see UserGroup}, with timestamps.
 *
 * Copied from externa-core without its chat-participant hooks (Instituta has no chat).
 *
 * @property int $user_group_id
 * @property int $user_id
 */
class UserGroupUser extends Pivot
{
    protected $table = 'user_group_user';

    public $incrementing = false;

    public $timestamps = true;
}
