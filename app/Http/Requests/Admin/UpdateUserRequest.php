<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Validates changes to a dashboard user. Users cannot deactivate themselves, and only a
 * super-admin may change their own roles or user groups.
 *
 * Copied from excubia-app.
 */
class UpdateUserRequest extends StoreUserRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->target());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($this->target())],
            'password' => ['nullable', 'string', Password::default()],
            'is_active' => ['boolean'],
            ...$this->roleRules(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $actor = $this->user();
            $target = $this->target();

            if (! $target->is($actor)) {
                return;
            }

            if (! $this->boolean('is_active')) {
                $validator->errors()->add('is_active', __('You cannot deactivate yourself.'));
            }

            if (app(EffectivePermissionResolverService::class)->isSuperAdmin($target)) {
                return;
            }

            if ($this->hasChangedOwnRoles($target)) {
                $validator->errors()->add('roles', __('You cannot change your own roles.'));
            }

            if ($this->hasChangedOwnGroups($target)) {
                $validator->errors()->add('group_ids', __('You cannot change your own groups.'));
            }
        }];
    }

    /**
     * Group roles count like direct roles, so changing your own groups is a role change too.
     */
    private function hasChangedOwnGroups(User $target): bool
    {
        $requested = $this->groupIds();

        if ($requested === null) {
            return false;
        }

        $current = $target->groups()->pluck('user_groups.id')->map(intval(...))->sort()->values()->all();

        return $current !== collect($requested)->unique()->sort()->values()->all();
    }

    private function hasChangedOwnRoles(User $target): bool
    {
        $current = $target->getRoleNames()->sort()->values()->all();
        $requested = collect($this->roles())->unique()->sort()->values()->all();

        return $current !== $requested;
    }

    private function target(): User
    {
        $target = $this->route('user');

        abort_unless($target instanceof User, 404);

        return $target;
    }
}
