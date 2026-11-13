import type { ColumnDef } from '@tanstack/react-table';
import { Head, setLayoutProps } from '@inertiajs/react';
import { Database, SearchX } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    DataTable,
    dataTableExpandColumn,
} from '@/components/admin/data-table';
import { TruncatedText } from '@/components/admin/truncated-text';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import brainRoutes from '@/routes/brain';
import type { BreadcrumbItem } from '@/types';

type SourceRow = {
    id: string;
    name: string;
    provider: string;
    sync_status: string;
    last_synced_commit: string | null;
    last_synced_at: string | null;
    last_sync_report: Record<string, unknown> | null;
    scope_type: string;
};

type Props = {
    sources: SourceRow[];
    hasPerimeter: boolean;
};

/**
 * Ingest sources list with sync status and expandable last report.
 */
export default function BrainSources({ sources, hasPerimeter }: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('brain.sources.title'),
                href: brainRoutes.sources.url(),
            },
        ],
        [t],
    );

    setLayoutProps({ breadcrumbs });

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
            cell: ({ row }) => row.original.last_synced_at ?? '—',
        },
    ];

    return (
        <>
            <Head title={t('brain.sources.title')} />
            <PageLayout
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
                                ? t('emptyState.sources.filteredDescription')
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
                            ) : undefined
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
        </>
    );
}
