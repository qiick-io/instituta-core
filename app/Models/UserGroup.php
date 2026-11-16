<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Authorization\EffectivePermissionResolverService;
use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\UserGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Named group of users that carries attached Spatie roles: members inherit those roles (and
 * their permissions) on top of their direct roles ({@see EffectivePermissionResolverService}).
 * A soft-deleted group grants nothing until it is restored.
 *
 * Copied from externa-core.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int|null $users_count
 * @property int|null $roles_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class UserGroup extends Model
{
    /** @use HasFactory<UserGroupFactory> */
    use HasFactory, LogsApplicationActivityTrait, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * @return BelongsToMany<User, $this, UserGroupUser>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_group_user')
            ->using(UserGroupUser::class)
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_group_role')->withTimestamps();
    }

    /**
     * Resolve route binding including soft-deleted groups, so the trash can restore and
     * permanently delete them.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->withTrashed()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->firstOrFail();
    }
}
