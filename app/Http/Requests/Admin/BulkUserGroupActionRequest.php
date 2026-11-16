<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\RoleGrantService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates bulk delete, restore and permanent delete of user groups.
 *
 * The whole batch is rejected when it touches a group carrying a role the actor could not
 * grant (the same reach rule as UserGroupPolicy).
 *
 * Copied from externa-core.
 */
class BulkUserGroupActionRequest extends FormRequest
{
    /** @var list<string> */
    public const ACTIONS = ['delete', 'restore', 'force_delete'];

    /**
     * Require the permission that matches the requested bulk action.
     */
    public function authorize(): bool
    {
        $permission = match ($this->input('action')) {
            'restore' => PermissionEnum::CanRestoreGroups,
            'force_delete' => PermissionEnum::CanForceDeleteGroups,
            default => PermissionEnum::CanDeleteGroups,
        };
        $actor = $this->user();

        return $actor instanceof User
            && app(EffectivePermissionResolverService::class)->hasPermission($actor, $permission->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', Rule::exists('user_groups', 'id')],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $actor = $this->user();

            if ($actor instanceof User && ! app(RoleGrantService::class)->canGrantGroups($actor, $this->array('ids'))) {
                $validator->errors()->add('ids', __('You cannot change groups that carry roles you cannot grant.'));
            }
        }];
    }

    /**
     * @return list<int>
     */
    public function groupIds(): array
    {
        return array_values(array_map(intval(...), $this->array('ids')));
    }
}
