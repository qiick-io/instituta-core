<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\UserGroupBrainAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * A user group for the admin list, its drawer and the group pickers (JSON), with the roles
 * and members when they are loaded.
 *
 * Copied from externa-core; `can_*` flags follow UserGroupPolicy, like UserResource.
 *
 * @mixin UserGroup
 */
class UserGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var UserGroup $group */
        $group = $this->resource;

        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => Str::slug($group->name),
            'description' => $group->description,
            'users_count' => $group->users_count,
            'roles_count' => $group->roles_count,
            'deleted_at' => $group->deleted_at?->toIso8601String(),
            // The group's brain access (#258) as the drawer's `scope_type:scope_id` value and role,
            // with its limits (#334).
            'brain_access' => $this->whenLoaded('brainAccess', fn (): array => $group->brainAccess
                ->map(fn (UserGroupBrainAccess $access): array => [
                    'scope' => $access->scope_type->value.':'.$access->scope_id,
                    'role' => $access->role->value,
                    'max_visibility' => $access->max_visibility?->value,
                    'areas' => $access->areas,
                    'path_prefixes' => $access->path_prefixes,
                ])
                ->values()
                ->all(), []),
            'roles' => $this->whenLoaded('roles', fn (): array => $group->roles
                ->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])
                ->values()
                ->all(), []),
            'users' => $this->whenLoaded('users', fn (): array => $group->users
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(), []),
            'user_ids' => $this->whenLoaded('users', fn (): array => $group->users->pluck('id')->values()->all(), []),
            'role_ids' => $this->whenLoaded('roles', fn (): array => $group->roles->pluck('id')->values()->all(), []),
            'can_update' => Gate::allows('update', $group),
            'can_delete' => Gate::allows('delete', $group),
            'can_restore' => Gate::allows('restore', $group),
            'can_force_delete' => Gate::allows('forceDelete', $group),
            'created_at' => $group->created_at?->toIso8601String(),
            'updated_at' => $group->updated_at?->toIso8601String(),
        ];
    }
}
