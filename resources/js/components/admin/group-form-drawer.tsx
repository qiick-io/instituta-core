import { useForm } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { PaginatedMultiSelect } from '@/components/admin/paginated-multi-select';
import {
    RoleMultiSelect,
    withSelectedRoles,
} from '@/components/admin/role-multi-select';
import { Button } from '@/components/ui/button';
import {
    DrawerBody,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { STRING_LIMITS } from '@/lib/string-limits';
import groups from '@/routes/groups';
import users from '@/routes/users';
import type {
    AdminBrainScopeOption,
    AdminGroupRow,
    AdminRoleRef,
} from '@/types/admin';

const EMPTY_GROUP_FORM = {
    name: '',
    description: '',
    role_ids: [] as number[],
    user_ids: [] as number[],
    /** '' gives no brain access (#258). */
    brain_scope: '',
    brain_role: 'member',
};

export type GroupFormDrawerProps = {
    editing: AdminGroupRow | null;
    /** Roles the current user may grant (and so attach to a group). */
    roles: AdminRoleRef[];
    /** Scopes the current user may give the group brain access to (#258). */
    brainScopes: AdminBrainScopeOption[];
    readOnly?: boolean;
    open?: boolean;
    onCancel?: () => void;
    onSuccess?: () => void;
};

/**
 * Drawer form for creating and editing user groups: name, description, the roles members
 * inherit and the members. Copied from externa-core; roles use Instituta's role picker
 * (names) and are sent as ids, members use the registry picker on `users.index`.
 * @param {*} props - Component props.
 * @returns {JSX.Element}
 */
export function GroupFormDrawer({
    editing,
    roles,
    brainScopes,
    readOnly = false,
    open = true,
    onCancel,
    onSuccess,
}: GroupFormDrawerProps) {
    const { t } = useTranslation();
    const { can } = useCan();
    // The member picker searches `users.index`, which needs `can-show-users`.
    const canPickUsers = can(PermissionEnum.CanShowUsers);
    const form = useForm({ ...EMPTY_GROUP_FORM });
    const roleOptions = withSelectedRoles(roles, editing?.roles ?? []);
    const memberOptions = useMemo(
        () =>
            (editing?.users ?? []).map((member) => ({
                id: member.id,
                label: `${member.name} (${member.email})`,
                first_name: member.first_name,
                last_name: member.last_name,
                email: member.email,
            })),
        [editing],
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        if (editing) {
            // Fresh object for setDefaults — avoid sharing the setData reference.
            const payload = {
                name: editing.name,
                description: editing.description ?? '',
                role_ids: editing.role_ids,
                user_ids: editing.user_ids,
                brain_scope: editing.brain_access[0]?.scope ?? '',
                brain_role: editing.brain_access[0]?.role ?? 'member',
            };
            form.setData(payload);
            form.setDefaults({ ...payload });
        } else {
            form.setDefaults({ ...EMPTY_GROUP_FORM });
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- Inertia form identity is unstable
    }, [editing, open]);

    const submit = (): void => {
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onSuccess?.();
            },
        };

        form.transform((data) => ({
            ...data,
            user_ids: canPickUsers ? data.user_ids : undefined,
        }));

        if (editing) {
            form.put(groups.update.url(editing.id), opts);
        } else {
            form.post(groups.store.url(), opts);
        }
    };

    const title = editing ? t('groups.form.editTitle') : t('groups.newGroup');

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>{title}</DrawerTitle>
                <DrawerDescription>
                    {t('groups.form.description')}
                </DrawerDescription>
            </DrawerHeader>

            <form
                className="flex min-h-0 flex-1 flex-col overflow-hidden"
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();

                    if (!readOnly) {
                        submit();
                    }
                }}
            >
                <DrawerBody>
                    <FieldGroup className="gap-4">
                        <Field
                            data-invalid={form.errors.name ? true : undefined}
                        >
                            <FieldLabel htmlFor="group_name">
                                {t('groups.form.nameRequired')}
                            </FieldLabel>
                            <Input
                                id="group_name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                                maxLength={STRING_LIMITS.NAME}
                                disabled={readOnly}
                                aria-invalid={
                                    form.errors.name ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.name
                                        ? 'group-name-error'
                                        : undefined
                                }
                            />
                            <FieldError id="group-name-error">
                                {form.errors.name}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                form.errors.description ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="group_description">
                                {t('groups.form.descriptionLabel')}
                            </FieldLabel>
                            <Input
                                id="group_description"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                                maxLength={STRING_LIMITS.DESCRIPTION}
                                disabled={readOnly}
                                aria-invalid={
                                    form.errors.description ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.description
                                        ? 'group-description-error'
                                        : undefined
                                }
                            />
                            <FieldError id="group-description-error">
                                {form.errors.description}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                form.errors.role_ids || form.errors['role_ids.0']
                                    ? true
                                    : undefined
                            }
                        >
                            <FieldLabel>{t('groups.columns.roles')}</FieldLabel>
                            <RoleMultiSelect
                                value={roleOptions
                                    .filter((role) =>
                                        form.data.role_ids.includes(role.id),
                                    )
                                    .map((role) => role.name)}
                                onChange={(roleNames) =>
                                    form.setData(
                                        'role_ids',
                                        roleOptions
                                            .filter((role) =>
                                                roleNames.includes(role.name),
                                            )
                                            .map((role) => role.id),
                                    )
                                }
                                roles={roleOptions}
                                disabled={readOnly}
                                placeholder={t('groups.form.rolesPlaceholder')}
                                aria-label={t('groups.columns.roles')}
                            />
                            <FieldDescription id="group-role-ids-hint">
                                {t('groups.form.rolesHint')}
                            </FieldDescription>
                            <FieldError id="group-role-ids-error">
                                {form.errors.role_ids ??
                                    form.errors['role_ids.0']}
                            </FieldError>
                        </Field>

                        {canPickUsers && (
                            <Field
                                data-invalid={
                                    form.errors.user_ids ? true : undefined
                                }
                            >
                                <FieldLabel>{t('groups.form.users')}</FieldLabel>
                                <PaginatedMultiSelect
                                    fetchUrl={users.index.url()}
                                    value={form.data.user_ids}
                                    onChange={(userIds) =>
                                        form.setData('user_ids', userIds)
                                    }
                                    initialOptions={memberOptions}
                                    showUserDetails
                                    disabled={readOnly}
                                    placeholder={t(
                                        'groups.form.usersPlaceholder',
                                    )}
                                    aria-label={t('groups.form.users')}
                                />
                                <FieldError id="group-user-ids-error">
                                    {form.errors.user_ids}
                                </FieldError>
                            </Field>
                        )}

                        {brainScopes.length > 0 ? (
                            <Field>
                                <FieldLabel htmlFor="group_brain_scope">
                                    {t('groups.form.brainAccess')}
                                </FieldLabel>
                                <div className="flex flex-wrap gap-2">
                                    <NativeSelect
                                        id="group_brain_scope"
                                        className="min-w-48 flex-1"
                                        value={form.data.brain_scope}
                                        disabled={readOnly}
                                        onChange={(event) =>
                                            form.setData(
                                                'brain_scope',
                                                event.target.value,
                                            )
                                        }
                                        aria-describedby="group-brain-access-hint"
                                    >
                                        <NativeSelectOption value="">
                                            {t('groups.form.noBrainAccess')}
                                        </NativeSelectOption>
                                        {brainScopes.map((scope) => (
                                            <NativeSelectOption
                                                key={scope.value}
                                                value={scope.value}
                                            >
                                                {scope.scope_type === 'company'
                                                    ? t('brain.scope.clientLabel', {
                                                          name: scope.label,
                                                      })
                                                    : scope.label}
                                            </NativeSelectOption>
                                        ))}
                                    </NativeSelect>
                                    <NativeSelect
                                        aria-label={t('groups.form.brainRole')}
                                        className="w-36"
                                        value={form.data.brain_role}
                                        disabled={
                                            readOnly || form.data.brain_scope === ''
                                        }
                                        onChange={(event) =>
                                            form.setData(
                                                'brain_role',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {(['owner', 'member', 'guest'] as const).map(
                                            (role) => (
                                                <NativeSelectOption
                                                    key={role}
                                                    value={role}
                                                >
                                                    {t(`brain.keys.roles.${role}`)}
                                                </NativeSelectOption>
                                            ),
                                        )}
                                    </NativeSelect>
                                </div>
                                <FieldDescription id="group-brain-access-hint">
                                    {t('groups.form.brainAccessHint')}
                                </FieldDescription>
                                <FieldError>
                                    {form.errors.brain_scope ??
                                        form.errors.brain_role}
                                </FieldError>
                            </Field>
                        ) : null}
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter className="flex flex-row justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onCancel?.()}
                    >
                        {t('common.cancel')}
                    </Button>
                    {!readOnly && (
                        <Button type="submit" disabled={form.processing}>
                            {editing ? t('common.save') : t('common.create')}
                        </Button>
                    )}
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}
