/** Normalized Laravel paginator shape used across admin list views. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

/** Permission names grouped by resource (`can-create-users` → `users`) for the role form. */
export type PermissionGroup = {
    section: string;
    label: string;
    permissions: string[];
};

/** Generic id/label pair for admin select components. */
export type AdminRoleRef = {
    id: number;
    name: string;
};

/** One row of `App\Http\Resources\Admin\UserResource`. */
export type AdminUserRow = {
    id: string;
    public_id?: string;
    first_name: string;
    last_name: string | null;
    username: string | null;
    /** First and last name. */
    name: string;
    email: string;
    is_active: boolean;
    email_verified_at: string | null;
    roles: AdminRoleRef[];
    /** User groups (`{ id, name }`); their roles add to the direct roles. */
    groups: AdminRoleRef[];
    can_update: boolean;
    /** False on your own account unless you are a super-admin. */
    can_change_roles: boolean;
    can_delete: boolean;
    created_at: string;
    updated_at: string;
};

/** A group member as `App\Http\Resources\Admin\UserGroupResource` lists it. */
export type AdminGroupMember = {
    id: string;
    public_id?: string;
    first_name: string;
    last_name: string | null;
    name: string;
    email: string;
};

/** One row of `App\Http\Resources\Admin\UserGroupResource`. */
export type AdminGroupRow = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    users_count: number | null;
    roles_count: number | null;
    deleted_at: string | null;
    roles: AdminRoleRef[];
    users: AdminGroupMember[];
    user_ids: string[];
    role_ids: number[];
    /**
     * Brain access the group gives its members (#258): `scope_type:scope_id` and a role, with an
     * optional visibility ceiling and area / path limits (#334; empty lists: no limit).
     */
    brain_access: {
        scope: string;
        role: string;
        max_visibility: string | null;
        areas: string[];
        path_prefixes: string[];
    }[];
    can_update: boolean;
    can_delete: boolean;
    can_restore: boolean;
    can_force_delete: boolean;
    created_at: string | null;
    updated_at: string | null;
};

/** One row of `App\Http\Resources\Admin\RoleResource`. */
export type AdminRoleRow = {
    id: number;
    name: string;
    is_built_in: boolean;
    can_edit: boolean;
    permissions_count?: number;
    permissions?: string[];
    created_at: string;
    updated_at: string;
};

/** One row of `App\Http\Resources\Admin\PermissionResource`. */
export type AdminPermissionRow = {
    id: number;
    name: string;
    guard_name: string;
    /** Names of the roles that hold the permission. */
    roles: string[];
    created_at: string;
    updated_at: string;
};

/** A scope a group can be given brain access to (#258): the organisation or one of its clients. */
export type AdminBrainScopeOption = {
    value: string;
    label: string;
    scope_type: 'organisation' | 'company';
};
