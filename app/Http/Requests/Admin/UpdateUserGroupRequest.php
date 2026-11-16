<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\UserGroup;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Unique;

/**
 * Validates changes to a user group. Groups carrying a role the actor could not grant are
 * out of reach (UserGroupPolicy); omitted `user_ids` / `role_ids` keep the current ones.
 *
 * Copied from externa-core.
 */
class UpdateUserGroupRequest extends StoreUserGroupRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->group());
    }

    protected function uniqueNameRule(): Unique
    {
        return parent::uniqueNameRule()->ignore($this->group()->id);
    }

    private function group(): UserGroup
    {
        $group = $this->route('group');

        abort_unless($group instanceof UserGroup, 404);

        return $group;
    }
}
