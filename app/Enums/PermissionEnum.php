<?php

declare(strict_types=1);

namespace App\Enums;

use App\Brain\Enums\RoleEnum;

/**
 * App-level permission names synced to the database and frontend enum.
 *
 * Trimmed from externa-core / excubia-app to Instituta admin surfaces for beta:
 * users, user groups, roles, permissions, activity logs, and settings. Brain tenancy ACL stays
 * on {@see RoleEnum} grants and keys.
 */
enum PermissionEnum: string
{
    case CanShowUsers = 'can-show-users';
    case CanCreateUsers = 'can-create-users';
    case CanEditUsers = 'can-edit-users';
    case CanDeleteUsers = 'can-delete-users';

    case CanShowGroups = 'can-show-groups';
    case CanCreateGroups = 'can-create-groups';
    case CanEditGroups = 'can-edit-groups';
    case CanDeleteGroups = 'can-delete-groups';
    case CanRestoreGroups = 'can-restore-groups';
    case CanForceDeleteGroups = 'can-force-delete-groups';

    case CanShowRoles = 'can-show-roles';
    case CanCreateRoles = 'can-create-roles';
    case CanEditRoles = 'can-edit-roles';
    case CanDeleteRoles = 'can-delete-roles';

    case CanShowPermissions = 'can-show-permissions';
    case CanEditPermissions = 'can-edit-permissions';

    case CanShowActivityLogs = 'can-show-activity-logs';

    case CanManageSettings = 'can-manage-settings';

    /** The in-app assistant: ask the brain and draft proposals (#203). */
    case CanUseAi = 'can-use-ai';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The resource a permission belongs to, for the role form sections
     * (`can-create-users` → `users`, `can-manage-settings` → `settings`).
     */
    public function resource(): string
    {
        return (string) preg_replace('/^can-[a-z]+-/', '', $this->value);
    }
}
