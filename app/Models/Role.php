<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsApplicationActivityTrait;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Spatie role with activity logging (`permission.models.role`), as in externa-core.
 *
 * @property bool $is_system
 * @property bool $is_assignable
 */
class Role extends SpatieRole
{
    use LogsApplicationActivityTrait;

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'is_system' => 'boolean',
            'is_assignable' => 'boolean',
        ];
    }
}
