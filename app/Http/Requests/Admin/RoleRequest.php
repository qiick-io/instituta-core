<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a custom role for both create and update. The actor may only put permissions
 * they hold into a role.
 *
 * Copied from excubia-app.
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role
            ? (bool) $this->user()?->can('update', $role)
            : (bool) $this->user()?->can('create', Role::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->route('role');
        $actor = $this->user();
        $heldPermissions = $actor instanceof User ? app(EffectivePermissionResolverService::class)->permissionsFor($actor) : [];

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::notIn(RoleEnum::values()),
                Rule::unique('roles', 'name')->ignore($role instanceof Role ? $role->id : null),
            ],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'distinct', Rule::enum(PermissionEnum::class), Rule::in($heldPermissions)],
        ];
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_filter($this->array('permissions'), is_string(...)));
    }
}
