import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Table2, SearchX } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { TruncatedText } from '@/components/admin/truncated-text';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import {
    index as tablesIndex,
    show as tablesShow,
} from '@/routes/brain/tables';
import type { BreadcrumbItem } from '@/types';

type TablesListRow = {
    id: string;
    table_key: string;
    item_id: string;
    item_title: string;
    item_path: string;
    columns: string[];
    row_count: number;
};

type Props = {
    tables: TablesListRow[];
    hasPerimeter: boolean;
};

/**
 * Structured tables list (tokens / registries) — list-page + DataTable (kickoff §5).
 */
export default function BrainTablesIndex({ tables, hasPerimeter }: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('brain.tables.title'),
                href: tablesIndex.url(),
            },
        ],
        [t],
    );

    setLayoutProps({ breadcrumbs });

    const filteredTables = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') {
            return tables;
        }

        return tables.filter(
            (row) =>
                row.table_key.toLowerCase().includes(needle) ||
                row.item_title.toLowerCase().includes(needle) ||
                row.item_path.toLowerCase().includes(needle),
        );
    }, [tables, search]);

    const hasActiveFilters = search.trim() !== '';
    const isListEmpty = filteredTables.length === 0;
    const hideListChrome =
        !hasPerimeter || (tables.length === 0 && !hasActiveFilters);

    const columns: ColumnDef<TablesListRow>[] = [
        {
            id: 'key',
            header: t('brain.tables.colKey'),
            meta: { cellClassName: 'max-w-[14rem] font-medium' },
            cell: ({ row }) => (
                <Link
                    href={tablesShow.url({
                        item: row.original.item_id,
                        table: row.original.table_key,
                    })}
                    className="underline-offset-4 hover:underline"
                >
                    <TruncatedText text={row.original.table_key} />
                </Link>
            ),
        },
        {
            id: 'item',
            header: t('brain.tables.colItem'),
            meta: { cellClassName: 'max-w-[16rem]' },
            cell: ({ row }) => (
                <div className="flex flex-col gap-0.5">
                    <TruncatedText text={row.original.item_title} />
                    <span className="font-mono text-xs text-muted-foreground">
                        {row.original.item_path}
                    </span>
                </div>
            ),
        },
        {
            id: 'rows',
            header: t('brain.tables.colRows'),
            cell: ({ row }) => (
                <Badge variant="secondary">{row.original.row_count}</Badge>
            ),
        },
        {
            id: 'columns',
            header: t('brain.tables.colColumns'),
            meta: {
                cellClassName: 'text-sm text-muted-foreground max-w-[16rem]',
            },
            cell: ({ row }) => (
                <TruncatedText text={row.original.columns.join(', ')} />
            ),
        },
    ];

    return (
        <>
            <Head title={t('brain.tables.title')} />
            <PageLayout
                description={t('brain.tables.description')}
                filters={
                    hideListChrome ? null : (
                        <DataTableToolbar
                            search={search}
                            onSearchChange={setSearch}
                            searchPlaceholder={t('brain.tables.colKey')}
                        />
                    )
                }
            >
                {!hasPerimeter ? (
                    <PageEmptyState
                        icon={SearchX}
                        title={
                            <EmptyStatus>{t('brain.scope.none')}</EmptyStatus>
                        }
                        description={t('brain.tables.description')}
                        compact
                    />
                ) : isListEmpty ? (
                    <PageEmptyState
                        icon={hasActiveFilters ? SearchX : Table2}
                        title={
                            hasActiveFilters ? (
                                <EmptyStatus>
                                    {t('brain.tables.empty')}
                                </EmptyStatus>
                            ) : (
                                t('brain.tables.title')
                            )
                        }
                        description={t('brain.tables.empty')}
                        compact
                    />
                ) : (
                    <DataTable
                        columns={columns}
                        data={filteredTables}
                        getRowId={(row) => row.id}
                    />
                )}
            </PageLayout>
        </>
    );
}
