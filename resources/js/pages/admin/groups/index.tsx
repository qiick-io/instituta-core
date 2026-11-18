import type { ColumnDef, RowSelectionState } from '@tanstack/react-table';
import { Head, router } from '@inertiajs/react';
import {
    ArrowDownAZ,
    ArrowUpAZ,
    Plus,
    SearchX,
    Trash2,
    UsersRound,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    DataTable,
    dataTableSelectColumn,
} from '@/components/admin/data-table';
import type { DataTableSort } from '@/components/admin/data-table';
import { DataTableColumnHeader } from '@/components/admin/data-table-column-header';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { GroupFormDrawer } from '@/components/admin/group-form-drawer';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { TruncatedText } from '@/components/admin/truncated-text';
import { UserAvatar } from '@/components/admin/user-avatar';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import {
    Avatar,
    AvatarFallback,
    AvatarGroup,
    AvatarGroupCount,
} from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Drawer } from '@/components/ui/drawer';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { SegmentedIconItem } from '@/components/admin/segmented-icon-item';
import { ToggleGroup } from '@/components/ui/toggle-group';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { useDebouncedServerSearch } from '@/hooks/use-debounced-server-search';
import { useDrawerDeepLink } from '@/hooks/use-drawer-deep-link';
import AppLayout from '@/layouts/app-layout';
import { avatarToneClass } from '@/lib/avatar-tone';
import { listPerPageQuery } from '@/lib/list-per-page';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import { cn } from '@/lib/utils';
import groupsRoutes from '@/routes/groups';
import type {
    AdminBrainScopeOption,
    AdminGroupRow,
    AdminRoleRef,
    BreadcrumbItem,
    Paginated,
} from '@/types';

/** Member avatars shown before the count in the Members column. */
const MEMBER_AVATAR_LIMIT = 3;

type GroupSortField = 'name' | 'created_at' | 'updated_at';
type GroupSortDirection = 'asc' | 'desc';
type GroupBulkAction = 'delete' | 'restore' | 'force_delete';

const GROUP_SORT_FIELDS: readonly GroupSortField[] = [
    'name',
    'created_at',
    'updated_at',
];

/**
 * @param value - Candidate sort field (URL, Select, column header).
 * @returns Whether the groups list can sort by it.
 */
function isGroupSortField(value: string): value is GroupSortField {
    return (GROUP_SORT_FIELDS as readonly string[]).includes(value);
}

type Filters = {
    search?: string;
    trashed?: boolean;
    sort?: GroupSortField;
    direction?: GroupSortDirection;
};

/**
 * Admin user groups list with search, sort, pagination, a trash view, bulk actions and a
 * create/edit drawer. Copied from externa-core (without its AI buttons), laid out like the
 * users list.
 */
export default function AdminGroupsIndex({
    groups: groupsProp,
    filters = {},
    roles,
    brainScopes = [],
    brainAreas = [],
}: {
    groups: LaravelPaginated<AdminGroupRow> | Paginated<AdminGroupRow>;
    filters?: Filters;
    roles: AdminRoleRef[];
    brainScopes?: AdminBrainScopeOption[];
    /** Areas the group's brain access can be limited to (#334). */
    brainAreas?: string[];
}) {
    const groups = normalizePaginated(groupsProp);
    const { t } = useTranslation();
    const { can } = useCan();

    const groupSortFields: { value: GroupSortField; label: string }[] = [
        { value: 'name', label: t('common.name') },
        { value: 'created_at', label: t('common.created') },
        { value: 'updated_at', label: t('common.updated') },
    ];
    const isTrashed = filters.trashed === true;
    const [search, setSearch] = useState(filters.search ?? '');
    const [sort, setSort] = useState<GroupSortField>(filters.sort ?? 'name');
    const [direction, setDirection] = useState<GroupSortDirection>(
        filters.direction ?? 'asc',
    );
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const selectedIds = useMemo(
        () =>
            Object.keys(rowSelection)
                .filter((id) => rowSelection[id])
                .map(Number),
        [rowSelection],
    );
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [editing, setEditing] = useState<AdminGroupRow | null>(null);
    const [pendingDeleteGroup, setPendingDeleteGroup] =
        useState<AdminGroupRow | null>(null);
    const [pendingForceDeleteGroup, setPendingForceDeleteGroup] =
        useState<AdminGroupRow | null>(null);
    const [pendingBulkAction, setPendingBulkAction] = useState<
        'delete' | 'force_delete' | null
    >(null);
    const [isConfirming, setIsConfirming] = useState(false);

    const deepLink = useDrawerDeepLink({
        onEdit: (id) => {
            const row = groups.data.find((group) => String(group.id) === id);

            if (!row?.can_update || isTrashed) {
                return;
            }

            setEditing(row);
            setDrawerOpen(true);
        },
        onNew: () => {
            if (!can(PermissionEnum.CanCreateGroups) || isTrashed) {
                return;
            }

            setEditing(null);
            setDrawerOpen(true);
        },
    });

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [{ title: t('groups.title'), href: groupsRoutes.index.url() }],
        [t],
    );

    const visit = useCallback(
        (overrides: Partial<Filters> = {}) => {
            const nextSearch =
                overrides.search !== undefined ? overrides.search : search;
            const nextTrashed =
                overrides.trashed !== undefined ? overrides.trashed : isTrashed;

            router.get(
                groupsRoutes.index.url({
                    query: {
                        search: nextSearch || undefined,
                        sort: overrides.sort ?? sort,
                        direction: overrides.direction ?? direction,
                        trashed: nextTrashed ? 1 : undefined,
                        per_page: listPerPageQuery(groups.per_page),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true },
            );
        },
        [direction, groups.per_page, isTrashed, search, sort],
    );

    useEffect(() => {
        setSearch(filters.search ?? '');
        setSort(filters.sort ?? 'name');
        setDirection(filters.direction ?? 'asc');
    }, [filters.search, filters.sort, filters.direction]);

    useEffect(() => {
        setRowSelection({});
    }, [isTrashed]);

    const onDebouncedSearch = useCallback(
        (value: string): void => {
            visit({ search: value || undefined });
        },
        [visit],
    );

    useDebouncedServerSearch(search, filters.search ?? '', onDebouncedSearch);

    const openCreate = (): void => {
        setEditing(null);
        setDrawerOpen(true);
        deepLink.syncNew();
    };

    const openEdit = (group: AdminGroupRow): void => {
        if (!group.can_update || isTrashed) {
            return;
        }

        setEditing(group);
        setDrawerOpen(true);
        deepLink.syncEdit(group.id);
    };

    const handleDrawerOpenChange = (open: boolean): void => {
        if (open) {
            setDrawerOpen(true);

            return;
        }

        setDrawerOpen(false);
        setEditing(null);
        deepLink.syncClosed();
    };

    const changeSort = (nextSort: DataTableSort): void => {
        if (!isGroupSortField(nextSort.id)) {
            return;
        }

        setSort(nextSort.id);
        setDirection(nextSort.direction);
        visit({ sort: nextSort.id, direction: nextSort.direction });
    };

    const runBulkAction = (action: GroupBulkAction): void => {
        setIsConfirming(true);
        router.post(
            groupsRoutes.bulkActions.url(),
            { ids: selectedIds, action },
            {
                preserveScroll: true,
                onFinish: () => setIsConfirming(false),
                onSuccess: () => {
                    setRowSelection({});
                    setPendingBulkAction(null);
                },
                onError: () => setPendingBulkAction(null),
            },
        );
    };

    const hasActiveFilters = search.trim() !== '';
    const isListEmpty = groups.data.length === 0;
    const hideListChrome = isListEmpty && !hasActiveFilters && !isTrashed;

    const columns: ColumnDef<AdminGroupRow>[] = [
        dataTableSelectColumn<AdminGroupRow>({
            selectAllLabel: t('a11y.selectAll'),
            getRowLabel: (group) => t('a11y.selectRow', { name: group.name }),
        }),
        {
            accessorKey: 'name',
            enableSorting: true,
            meta: { cellClassName: 'max-w-[14rem] font-medium' },
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('groups.columns.name')}
                />
            ),
            cell: ({ row }) => (
                <span className="inline-flex min-w-0 items-center gap-2">
                    <Avatar size="sm" aria-hidden>
                        <AvatarFallback
                            className={cn(
                                avatarToneClass(`group-${row.original.id}`),
                                'text-xs font-medium',
                            )}
                        >
                            {row.original.name.trim().charAt(0).toUpperCase()}
                        </AvatarFallback>
                    </Avatar>
                    <TruncatedText text={row.original.name} />
                </span>
            ),
        },
        {
            id: 'slug',
            header: t('groups.columns.slug'),
            meta: { cellClassName: 'max-w-[14rem]' },
            cell: ({ row }) => (
                <TruncatedText
                    text={row.original.slug}
                    className="font-mono text-xs text-muted-foreground"
                />
            ),
        },
        {
            id: 'roles',
            header: t('groups.columns.roles'),
            cell: ({ row }) => (
                <div className="flex flex-wrap gap-1">
                    {row.original.roles.map((role) => (
                        <Badge key={role.id} variant="secondary">
                            {role.name}
                        </Badge>
                    ))}
                </div>
            ),
        },
        {
            id: 'members',
            header: t('groups.columns.members'),
            meta: {
                headerClassName: 'text-right',
                cellClassName: 'text-right tabular-nums',
            },
            cell: ({ row }) => {
                const members = row.original.users;
                const total = row.original.users_count ?? members.length;
                const shownMembers = members.slice(0, MEMBER_AVATAR_LIMIT);
                const hiddenCount = total - shownMembers.length;

                if (members.length === 0) {
                    return total > 0 ? total : '—';
                }

                return (
                    <AvatarGroup
                        className="justify-end"
                        aria-label={t('groups.membersCount', { count: total })}
                    >
                        {shownMembers.map((member) => (
                            <UserAvatar
                                key={member.id}
                                userId={member.id}
                                name={member.name}
                                email={member.email}
                                size="sm"
                            />
                        ))}
                        {hiddenCount > 0 ? (
                            <AvatarGroupCount className="text-xs">
                                +{hiddenCount}
                            </AvatarGroupCount>
                        ) : null}
                    </AvatarGroup>
                );
            },
        },
        {
            id: 'actions',
            header: t('groups.columns.actions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
                isRowClickIgnored: true,
            },
            cell: ({ row }) => {
                const group = row.original;

                if (!isTrashed) {
                    return group.can_delete ? (
                        <ButtonGroup
                            aria-label={t('groups.columns.actions')}
                            className="ml-auto"
                        >
                            <HeaderIconButton
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                className="text-destructive hover:text-destructive"
                                label={t('common.delete')}
                                onClick={() => setPendingDeleteGroup(group)}
                            >
                                <Trash2 className="size-3.5" />
                            </HeaderIconButton>
                        </ButtonGroup>
                    ) : null;
                }

                return (
                    <ButtonGroup
                        aria-label={t('groups.columns.actions')}
                        className="ml-auto"
                    >
                        {group.can_restore && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        groupsRoutes.restore.url(group.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('common.restore')}
                            </Button>
                        )}
                        {group.can_force_delete && (
                            <Button
                                type="button"
                                variant="destructive-outline"
                                size="sm"
                                onClick={() =>
                                    setPendingForceDeleteGroup(group)
                                }
                            >
                                {t('common.deletePermanently')}
                            </Button>
                        )}
                    </ButtonGroup>
                );
            },
        },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                can(PermissionEnum.CanCreateGroups) && !isTrashed ? (
                    <Button type="button" onClick={openCreate}>
                        <Plus className="mr-1 size-4" />
                        {t('groups.newGroup')}
                    </Button>
                ) : undefined
            }
        >
            <Head title={t('groups.title')} />

            <Drawer
                direction="right"
                open={drawerOpen}
                onOpenChange={handleDrawerOpenChange}
            >
                <PageLayout
                    filters={
                        hideListChrome ? undefined : (
                            <DataTableToolbar
                                search={search}
                                onSearchChange={setSearch}
                                searchPlaceholder={
                                    isTrashed
                                        ? t('groups.searchTrashPlaceholder')
                                        : t('groups.searchPlaceholder')
                                }
                                selectedCount={selectedIds.length}
                                onClearSelection={() => setRowSelection({})}
                                bulkActions={
                                    <>
                                        {!isTrashed &&
                                            can(
                                                PermissionEnum.CanDeleteGroups,
                                            ) && (
                                                <Button
                                                    type="button"
                                                    variant="destructive-outline"
                                                    onClick={() =>
                                                        setPendingBulkAction(
                                                            'delete',
                                                        )
                                                    }
                                                >
                                                    {t('common.delete')}
                                                </Button>
                                            )}
                                        {isTrashed &&
                                            can(
                                                PermissionEnum.CanRestoreGroups,
                                            ) && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        runBulkAction('restore')
                                                    }
                                                >
                                                    {t('common.restore')}
                                                </Button>
                                            )}
                                        {isTrashed &&
                                            can(
                                                PermissionEnum.CanForceDeleteGroups,
                                            ) && (
                                                <Button
                                                    type="button"
                                                    variant="destructive-outline"
                                                    onClick={() =>
                                                        setPendingBulkAction(
                                                            'force_delete',
                                                        )
                                                    }
                                                >
                                                    {t(
                                                        'common.deletePermanently',
                                                    )}
                                                </Button>
                                            )}
                                    </>
                                }
                            />
                        )
                    }
                    filtersRight={
                        hideListChrome || selectedIds.length > 0 ? null : (
                            <div className="flex items-center gap-1.5">
                                <Select
                                    value={sort}
                                    onValueChange={(value) => {
                                        if (isGroupSortField(value)) {
                                            setSort(value);
                                            visit({ sort: value });
                                        }
                                    }}
                                >
                                    <SelectTrigger
                                        aria-label={t('groups.sortBy')}
                                        className="w-[7.5rem]"
                                    >
                                        <SelectValue
                                            placeholder={t('groups.sortBy')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent align="end">
                                        {groupSortFields.map((field) => (
                                            <SelectItem
                                                key={field.value}
                                                value={field.value}
                                            >
                                                {field.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label={
                                        direction === 'asc'
                                            ? t('common.sortAscending')
                                            : t('common.sortDescending')
                                    }
                                    onClick={() => {
                                        const nextDirection =
                                            direction === 'asc'
                                                ? 'desc'
                                                : 'asc';
                                        setDirection(nextDirection);
                                        visit({ direction: nextDirection });
                                    }}
                                >
                                    {direction === 'asc' ? (
                                        <ArrowUpAZ className="size-4" />
                                    ) : (
                                        <ArrowDownAZ className="size-4" />
                                    )}
                                </Button>
                                <ToggleGroup
                                    variant="outline"
                                    type="single"
                                    value={isTrashed ? 'trashed' : 'active'}
                                    onValueChange={(value) => {
                                        if (!value) {
                                            return;
                                        }

                                        visit({ trashed: value === 'trashed' });
                                    }}
                                >
                                    <SegmentedIconItem
                                        value="active"
                                        isActive={!isTrashed}
                                        label={t('groups.title')}
                                    >
                                        <UsersRound className="size-4" />
                                    </SegmentedIconItem>
                                    <SegmentedIconItem
                                        value="trashed"
                                        isActive={isTrashed}
                                        label={t('a11y.trashList')}
                                    >
                                        <Trash2 className="size-4" />
                                    </SegmentedIconItem>
                                </ToggleGroup>
                            </div>
                        )
                    }
                >
                    {isListEmpty ? (
                        <PageEmptyState
                            icon={
                                hasActiveFilters
                                    ? SearchX
                                    : isTrashed
                                      ? Trash2
                                      : UsersRound
                            }
                            title={
                                <EmptyStatus>
                                    {hasActiveFilters
                                        ? t('emptyState.groups.filteredTitle')
                                        : isTrashed
                                          ? t('emptyState.groups.trashTitle')
                                          : t('emptyState.groups.title')}
                                </EmptyStatus>
                            }
                            description={
                                hasActiveFilters
                                    ? t('emptyState.groups.filteredDescription')
                                    : isTrashed
                                      ? t('emptyState.groups.trashDescription')
                                      : t('emptyState.groups.description')
                            }
                            action={
                                hasActiveFilters ? (
                                    <Button
                                        type="button"
                                        onClick={() => {
                                            setSearch('');
                                            visit({ search: undefined });
                                        }}
                                    >
                                        {t('common.clearFilters')}
                                    </Button>
                                ) : isTrashed ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            visit({ trashed: false })
                                        }
                                    >
                                        {t('emptyState.groups.backToList')}
                                    </Button>
                                ) : can(PermissionEnum.CanCreateGroups) ? (
                                    <Button type="button" onClick={openCreate}>
                                        <Plus className="size-4" />
                                        {t('emptyState.groups.cta')}
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <DataTable
                            columns={columns}
                            data={groups.data}
                            getRowId={(group) => String(group.id)}
                            sort={{ id: sort, direction }}
                            onSortChange={changeSort}
                            rowSelection={rowSelection}
                            onRowSelectionChange={setRowSelection}
                            pagination={groups}
                            onRowClick={isTrashed ? undefined : openEdit}
                            getRowProps={(group) => ({
                                className:
                                    group.can_update && !isTrashed
                                        ? 'cursor-pointer'
                                        : undefined,
                            })}
                        />
                    )}
                </PageLayout>

                <GroupFormDrawer
                    editing={editing}
                    brainScopes={brainScopes}
                    brainAreas={brainAreas}
                    roles={roles}
                    open={drawerOpen}
                    readOnly={
                        editing
                            ? !editing.can_update
                            : !can(PermissionEnum.CanCreateGroups)
                    }
                    onCancel={() => handleDrawerOpenChange(false)}
                    onSuccess={() => {
                        setDrawerOpen(false);
                        setEditing(null);
                        deepLink.syncClosed();
                    }}
                />
            </Drawer>

            <ConfirmDestructiveDialog
                open={pendingDeleteGroup !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingDeleteGroup(null);
                    }
                }}
                title={t('groups.confirm.deleteTitle', {
                    name: pendingDeleteGroup?.name ?? '',
                })}
                description={t('groups.confirm.deleteDescription')}
                confirming={isConfirming}
                onConfirm={() => {
                    if (pendingDeleteGroup === null) {
                        return;
                    }

                    setIsConfirming(true);
                    router.delete(
                        groupsRoutes.destroy.url(pendingDeleteGroup.id),
                        {
                            preserveScroll: true,
                            onFinish: () => setIsConfirming(false),
                            onSuccess: () => setPendingDeleteGroup(null),
                            onError: () => setPendingDeleteGroup(null),
                        },
                    );
                }}
            />

            <ConfirmDestructiveDialog
                open={pendingBulkAction !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingBulkAction(null);
                    }
                }}
                title={
                    pendingBulkAction === 'force_delete'
                        ? t('groups.confirm.bulkForceDeleteTitle', {
                              count: selectedIds.length,
                          })
                        : t('groups.confirm.bulkDeleteTitle', {
                              count: selectedIds.length,
                          })
                }
                description={
                    pendingBulkAction === 'force_delete'
                        ? t('groups.confirm.bulkForceDeleteDescription')
                        : t('groups.confirm.bulkDeleteDescription')
                }
                confirmLabel={
                    pendingBulkAction === 'force_delete'
                        ? t('common.deletePermanently')
                        : t('common.delete')
                }
                confirming={isConfirming}
                onConfirm={() => {
                    if (pendingBulkAction !== null) {
                        runBulkAction(pendingBulkAction);
                    }
                }}
            />

            <ConfirmDestructiveDialog
                open={pendingForceDeleteGroup !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingForceDeleteGroup(null);
                    }
                }}
                title={t('groups.confirm.forceDeleteTitle', {
                    name: pendingForceDeleteGroup?.name ?? '',
                })}
                description={t('groups.confirm.forceDeleteDescription')}
                confirmLabel={t('common.deletePermanently')}
                typedConfirmation={pendingForceDeleteGroup?.name}
                confirming={isConfirming}
                onConfirm={() => {
                    if (pendingForceDeleteGroup === null) {
                        return;
                    }

                    setIsConfirming(true);
                    router.delete(
                        groupsRoutes.forceDelete.url(
                            pendingForceDeleteGroup.id,
                        ),
                        {
                            preserveScroll: true,
                            onFinish: () => setIsConfirming(false),
                            onSuccess: () => setPendingForceDeleteGroup(null),
                            onError: () => setPendingForceDeleteGroup(null),
                        },
                    );
                }}
            />
        </AppLayout>
    );
}
