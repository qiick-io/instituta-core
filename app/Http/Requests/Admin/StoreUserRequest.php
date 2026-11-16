<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\RoleGrantService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates a new dashboard user. The actor may only grant roles they hold, directly or
 * through user groups.
 *
 * Copied from excubia-app.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', User::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::default()],
            'is_active' => ['boolean'],
            ...$this->roleRules(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function assignableRolesFor(?User $actor): array
    {
        return $actor === null ? [] : app(RoleGrantService::class)->grantableRoleNames($actor);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function assignableRoleOptionsFor(?User $actor): array
    {
        return array_values(Role::query()
            ->whereIn('name', self::assignableRolesFor($actor))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Role $role): array => ['id' => (int) $role->id, 'name' => $role->name])
            ->all());
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return array_values(array_filter($this->array('roles'), is_string(...)));
    }

    /**
     * User group ids when the payload has them; null keeps the current groups.
     *
     * @return list<int>|null
     */
    public function groupIds(): ?array
    {
        return $this->has('group_ids') ? array_values(array_map(intval(...), $this->array('group_ids'))) : null;
    }

    /**
     * Direct roles, plus user groups: joining a group grants its roles, so the actor may only
     * pick groups whose roles they could grant directly.
     *
     * @return array<string, array<mixed>>
     */
    protected function roleRules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in(self::assignableRolesFor($this->user()))],
            'group_ids' => ['sometimes', 'array', function (string $attribute, mixed $groupIds, Closure $fail): void {
                $actor = $this->user();

                if (is_array($groupIds) && (! $actor instanceof User || ! app(RoleGrantService::class)->canGrantGroups($actor, $groupIds))) {
                    $fail(__('You cannot assign groups that carry roles you cannot grant.'));
                }
            }],
            'group_ids.*' => ['integer', 'distinct', Rule::exists('user_groups', 'id')->whereNull('deleted_at')],
        ];
    }
}
