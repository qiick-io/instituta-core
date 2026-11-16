<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Brain\Enums\RoleEnum as BrainRoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\RoleGrantService;
use App\Services\GroupBrainScopesService;
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
            // Brain access (#258): '' removes it; otherwise a scope the actor owns, with a role.
            'brain_scope' => ['sometimes', 'nullable', 'string', Rule::in(array_column($this->brainScopeOptions(), 'value'))],
            'brain_role' => ['required_with_all:brain_scope', 'nullable', Rule::enum(BrainRoleEnum::class)],
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

    /**
     * The group's brain access when the payload has it: an empty list removes it, null keeps it.
     *
     * @return list<array{organisation_id: string, scope_type: string, scope_id: string, role: string}>|null
     */
    public function brainAccess(): ?array
    {
        if (! $this->has('brain_scope')) {
            return null;
        }

        $scope = $this->string('brain_scope')->toString();
        $user = $this->user();
        $option = $scope === '' || ! $user instanceof User ? null : app(GroupBrainScopesService::class)->find($user, $scope);

        if ($option === null) {
            return [];
        }

        return [[
            'organisation_id' => $option['organisation_id'],
            'scope_type' => $option['scope_type'],
            'scope_id' => $option['scope_id'],
            'role' => $this->string('brain_role')->toString(),
        ]];
    }

    protected function uniqueNameRule(): Unique
    {
        return Rule::unique('user_groups', 'name');
    }

    /**
     * @return list<array{value: string, label: string, organisation_id: string, scope_type: string, scope_id: string}>
     */
    private function brainScopeOptions(): array
    {
        $user = $this->user();

        return $user instanceof User ? app(GroupBrainScopesService::class)->optionsFor($user) : [];
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
