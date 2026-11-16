import type { ColumnDef } from '@tanstack/react-table';
import { Head, router } from '@inertiajs/react';
import {
    Database,
    Pencil,
    Plus,
    RefreshCw,
    SearchX,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    DataTable,
    dataTableExpandColumn,
} from '@/components/admin/data-table';
import SourceSyncController from '@/actions/App/Http/Controllers/Brain/SourceSyncController';
import SourcesController from '@/actions/App/Http/Controllers/Brain/SourcesController';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { TruncatedText } from '@/components/admin/truncated-text';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import {
    SourceFormDrawer,
    type SourceFormRow,
} from '@/components/brain/source-form-drawer';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Drawer } from '@/components/ui/drawer';
import { index as repositoriesIndex } from '@/routes/repositories';
import type { BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editProfile } from '@/routes/profile';
import Heading from '@/components/heading';
import { formatRelativeTime } from '@/lib/relative-time';

type SourceRow = SourceFormRow & {
    id: string;
    name: string;
    provider: string;
    sync_status: string;
    last_synced_commit: string | null;
    last_synced_at: string | null;
    last_sync_report: Record<string, unknown> | null;
    scope_type: string;
    has_webhook: boolean;
};

type Props = {
    sources: SourceRow[];
    hasPerimeter: boolean;
    /** Owners add, edit, sync and remove sources (#201). */
    canManage: boolean;
};

/**
 * Ingest sources list with sync status and expandable last report. Owners manage sources from
 * here (#201): add / edit in a drawer, sync now, remove with confirmation.
 */
export default function BrainSources({
    sources,
    hasPerimeter,
    canManage,
}: Props) {
    const { t, i18n } = useTranslation();
    const [search, setSearch] = useState('');
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [editing, setEditing] = useState<SourceRow | null>(null);
    const [pendingRemoval, setPendingRemoval] = useState<SourceRow | null>(
        null,
    );
    const [isRemoving, setIsRemoving] = useState(false);

    const openEdit = (source: SourceRow): void => {
        setEditing(source);
        setIsDrawerOpen(true);
    };

    const closeDrawer = (): void => {
        setIsDrawerOpen(false);
        setEditing(null);
    };

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            { title: t('settings.layout.title'), href: editProfile() },
            {
                title: t('settings.layout.repositories'),
                href: repositoriesIndex.url(),
            },
        ],
        [t],
    );

    // The primary action sits in the header, or in the empty state when the list is empty,
    // as in externa-core.
    const primaryAction = useMemo(
        () =>
            canManage ? (
                <Button
                    type="button"
                    onClick={() => {
                        setEditing(null);
                        setIsDrawerOpen(true);
                    }}
                >
                    <Plus className="mr-1 size-4" />
                    {t('brain.sources.create')}
                </Button>
            ) : undefined,
        [canManage, t],
    );

    const layoutProps = { breadcrumbs };

    const filteredSources = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') {
            return sources;
        }

        return sources.filter(
            (source) =>
                source.name.toLowerCase().includes(needle) ||
                source.provider.toLowerCase().includes(needle) ||
                source.sync_status.toLowerCase().includes(needle),
        );
    }, [sources, search]);

    const hasActiveFilters = search.trim() !== '';
    const isListEmpty = filteredSources.length === 0;
    const hideListChrome =
        !hasPerimeter || (sources.length === 0 && !hasActiveFilters);
    const hasExpandableReport = filteredSources.some(
        (source) => source.last_sync_report !== null,
    );

    const columns: ColumnDef<SourceRow>[] = [
        ...(hasExpandableReport
            ? [
                  dataTableExpandColumn<SourceRow>({
                      expandLabel: t('brain.sources.showReport'),
                      collapseLabel: t('brain.sources.hideReport'),
                  }),
              ]
            : []),
        {
            id: 'name',
            header: t('brain.sources.colName'),
            meta: { cellClassName: 'max-w-[14rem] font-medium' },
            cell: ({ row }) => <TruncatedText text={row.original.name} />,
        },
        {
            id: 'provider',
            header: t('brain.sources.colProvider'),
            cell: ({ row }) => (
                <Badge variant="outline">{row.original.provider}</Badge>
            ),
        },
        {
            id: 'status',
            header: t('brain.sources.colStatus'),
            cell: ({ row }) => (
                <Badge
                    variant={
                        row.original.sync_status === 'failed'
                            ? 'destructive'
                            : row.original.sync_status === 'ok'
                              ? 'success'
                              : 'secondary'
                    }
                >
                    {t(`brain.sources.status.${row.original.sync_status}`, {
                        defaultValue: row.original.sync_status,
                    })}
                </Badge>
            ),
        },
        {
            id: 'commit',
            header: t('brain.sources.colCommit'),
            meta: { cellClassName: 'font-mono text-xs' },
            cell: ({ row }) =>
                row.original.last_synced_commit
                    ? row.original.last_synced_commit.slice(0, 7)
                    : '—',
        },
        {
            id: 'synced',
            header: t('brain.sources.colSynced'),
            meta: { cellClassName: 'text-sm text-muted-foreground' },
            cell: ({ row }) =>
                row.original.last_synced_at === null
                    ? '—'
                    : formatRelativeTime(
                          Date.parse(row.original.last_synced_at) / 1000,
                          i18n.language,
                      ),
        },
        ...(canManage
            ? [
                  {
                      id: 'actions',
                      header: t('brain.sources.colActions'),
                      meta: {
                          headerClassName: 'w-[1%] text-right',
                          cellClassName: 'text-right',
                      },
                      cell: ({ row }) => (
                          <ButtonGroup className="justify-end">
                              <HeaderIconButton
                                  type="button"
                                  variant="outline"
                                  size="icon-sm"
                                  label={t('brain.sources.syncNow')}
                                  disabled={
                                      row.original.sync_status === 'syncing'
                                  }
                                  onClick={() =>
                                      router.post(
                                          SourceSyncController.url(
                                              row.original.id,
                                          ),
                                          {},
                                          { preserveScroll: true },
                                      )
                                  }
                              >
                                  <RefreshCw className="size-3.5" />
                              </HeaderIconButton>
                              <HeaderIconButton
                                  type="button"
                                  variant="outline"
                                  size="icon-sm"
                                  label={t('brain.sources.edit')}
                                  onClick={() => openEdit(row.original)}
                              >
                                  <Pencil className="size-3.5" />
                              </HeaderIconButton>
                              <HeaderIconButton
                                  type="button"
                                  variant="outline"
                                  size="icon-sm"
                                  className="text-destructive hover:text-destructive"
                                  label={t('brain.sources.remove')}
                                  onClick={() =>
                                      setPendingRemoval(row.original)
                                  }
                              >
                                  <Trash2 className="size-3.5" />
                              </HeaderIconButton>
                          </ButtonGroup>
                      ),
                  } satisfies ColumnDef<SourceRow>,
              ]
            : []),
    ];

    return (
        <AppLayout
            {...layoutProps}
            headerActions={hideListChrome ? undefined : primaryAction}
        >
            <Head title={t('settings.layout.repositories')} />
            <SettingsLayout wide>
                <Heading
                    variant="small"
                    title={t('settings.layout.repositories')}
                    description={t('settings.repositories.description')}
                />
                <PageLayout
                    className="p-0"
                    filters={
                        hideListChrome ? undefined : (
                            <DataTableToolbar
                                search={search}
                                onSearchChange={setSearch}
                                searchPlaceholder={t(
                                    'brain.sources.searchPlaceholder',
                                )}
                            />
                        )
                    }
                >
                    {!hasPerimeter ? (
                        <p className="text-sm text-muted-foreground">
                            {t('brain.scope.none')}
                        </p>
                    ) : isListEmpty ? (
                        <PageEmptyState
                            icon={hasActiveFilters ? SearchX : Database}
                            title={
                                <EmptyStatus>
                                    {hasActiveFilters
                                        ? t('emptyState.sources.filteredTitle')
                                        : t('emptyState.sources.title')}
                                </EmptyStatus>
                            }
                            description={
                                hasActiveFilters
                                    ? t(
                                          'emptyState.sources.filteredDescription',
                                      )
                                    : t('emptyState.sources.description')
                            }
                            action={
                                hasActiveFilters ? (
                                    <Button
                                        type="button"
                                        onClick={() => setSearch('')}
                                    >
                                        {t('common.clearFilters')}
                                    </Button>
                                ) : (
                                    primaryAction
                                )
                            }
                        />
                    ) : (
                        <DataTable
                            columns={columns}
                            data={filteredSources}
                            getRowId={(source) => source.id}
                            renderExpandedRow={
                                hasExpandableReport
                                    ? (source) =>
                                          source.last_sync_report ? (
                                              <pre className="max-w-3xl overflow-x-auto rounded-md bg-muted/40 p-2 font-mono text-xs">
                                                  {JSON.stringify(
                                                      source.last_sync_report,
                                                      null,
                                                      2,
                                                  )}
                                              </pre>
                                          ) : null
                                    : undefined
                            }
                        />
                    )}
                </PageLayout>
            </SettingsLayout>

            {canManage ? (
                <Drawer
                    direction="right"
                    open={isDrawerOpen}
                    onOpenChange={(isOpen) => {
                        if (!isOpen) {
                            closeDrawer();
                        }
                    }}
                >
                    <SourceFormDrawer
                        editing={editing}
                        open={isDrawerOpen}
                        onCancel={closeDrawer}
                        onSuccess={closeDrawer}
                    />
                </Drawer>
            ) : null}

            <ConfirmDestructiveDialog
                open={pendingRemoval !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) {
                        setPendingRemoval(null);
                    }
                }}
                title={t('brain.sources.confirmRemoveTitle', {
                    name: pendingRemoval?.name ?? '',
                })}
                description={t('brain.sources.confirmRemoveDescription')}
                confirmLabel={t('brain.sources.remove')}
                confirming={isRemoving}
                onConfirm={() => {
                    if (pendingRemoval === null) {
                        return;
                    }

                    setIsRemoving(true);
                    router.delete(
                        SourcesController.destroy.url(pendingRemoval.id),
                        {
                            preserveScroll: true,
                            onFinish: () => setIsRemoving(false),
                            onSuccess: () => setPendingRemoval(null),
                        },
                    );
                }}
            />
        </AppLayout>
    );
}
