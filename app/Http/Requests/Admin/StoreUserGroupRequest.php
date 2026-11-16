<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\RoleGrantService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Validates a new user group with its members and roles. The actor may only attach roles
 * they could grant directly (RoleGrantService): members inherit them.
 *
 * Copied from externa-core; limits match `STRING_LIMITS` in the frontend.
 */
class StoreUserGroupRequest extends FormRequest
{
    public const NAME_MAX_LENGTH = 255;

    public const DESCRIPTION_MAX_LENGTH = 1000;

    public function authorize(): bool
    {
        return Gate::allows('create', UserGroup::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH, $this->uniqueNameRule()],
            'description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'distinct', Rule::in($this->grantableRoleIds())],
        ];
    }

    /**
     * Member ids when the payload has them; null keeps the current members.
     *
     * @return list<int>|null
     */
    public function userIds(): ?array
    {
        return $this->has('user_ids') ? array_values(array_map(intval(...), $this->array('user_ids'))) : null;
    }

    /**
     * Role ids when the payload has them; null keeps the current roles.
     *
     * @return list<int>|null
     */
    public function roleIds(): ?array
    {
        return $this->has('role_ids') ? array_values(array_map(intval(...), $this->array('role_ids'))) : null;
    }

    protected function uniqueNameRule(): Unique
    {
        return Rule::unique('user_groups', 'name');
    }

    /**
     * Assignable roles the actor could grant to a user directly.
     *
     * @return list<int>
     */
    private function grantableRoleIds(): array
    {
        $actor = $this->user();

        if (! $actor instanceof User) {
            return [];
        }

        return array_values(Role::query()
            ->where('is_assignable', true)
            ->whereIn('name', app(RoleGrantService::class)->grantableRoleNames($actor))
            ->pluck('id')
            ->map(intval(...))
            ->all());
    }
}
