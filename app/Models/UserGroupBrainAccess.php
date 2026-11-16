<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The brain access a user group gives its members (#258): one scope (the organisation or a client
 * company) and a role; visibility follows the role, as for a person's own access.
 *
 * @property int $id
 * @property int $user_group_id
 * @property string $organisation_id
 * @property ScopeTypeEnum $scope_type
 * @property string $scope_id
 * @property RoleEnum $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_group_id', 'organisation_id', 'scope_type', 'scope_id', 'role'])]
class UserGroupBrainAccess extends Model
{
    protected $table = 'user_group_brain_access';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => ScopeTypeEnum::class,
            'role' => RoleEnum::class,
        ];
    }

    /**
     * @return BelongsTo<UserGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class, 'user_group_id');
    }
}
