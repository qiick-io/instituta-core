import type { ColumnDef } from '@tanstack/react-table';
import { Head, setLayoutProps } from '@inertiajs/react';
import { KeyRound, SearchX } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { TruncatedText } from '@/components/admin/truncated-text';
import { DataTable } from '@/components/admin/data-table';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import keysRoutes from '@/routes/brain';
import type { BreadcrumbItem } from '@/types';

type KeyRow = {
    id: string;
    name: string;
    display_prefix: string;
    scope_type: string;
    scope_id: string;
    role: string;
    max_visibility: string;
    last_used_at: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    revoked: boolean;
};

type Props = {
    is_owner: boolean;
    keys: KeyRow[];
};

/**
 * Read-only API keys list for organisation owners (create/revoke stay in Artisan).
 */
export default function BrainKeys({ is_owner, keys }: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('brain.keys.title'),
                href: keysRoutes.keys.url(),
            },
        ],
        [t],
    );

    setLayoutProps({ breadcrumbs });

    const filteredKeys = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') {
            return keys;
        }

        return keys.filter(
            (key) =>
                key.name.toLowerCase().includes(needle) ||
                key.display_prefix.toLowerCase().includes(needle) ||
                key.scope_type.toLowerCase().includes(needle) ||
                key.role.toLowerCase().includes(needle),
        );
    }, [keys, search]);

    const hasActiveFilters = search.trim() !== '';
    const isListEmpty = filteredKeys.length === 0;
    const hideListChrome =
        !is_owner || (keys.length === 0 && !hasActiveFilters);

    const columns: ColumnDef<KeyRow>[] = [
        {
            id: 'name',
            header: t('brain.keys.colName'),
            meta: { cellClassName: 'max-w-[14rem] font-medium' },
            cell: ({ row }) => <TruncatedText text={row.original.name} />,
        },
        {
            id: 'prefix',
            header: t('brain.keys.colPrefix'),
            meta: { cellClassName: 'font-mono text-xs' },
            cell: ({ row }) => row.original.display_prefix,
        },
        {
            id: 'scope',
            header: t('brain.keys.colScope'),
            cell: ({ row }) => (
                <Badge variant="outline">{row.original.scope_type}</Badge>
            ),
        },
        {
            id: 'role',
            header: t('brain.keys.colRole'),
            cell: ({ row }) => row.original.role,
        },
        {
            id: 'last_used',
            header: t('brain.keys.colLastUsed'),
            meta: { cellClassName: 'text-sm text-muted-foreground' },
            cell: ({ row }) => row.original.last_used_at ?? '—',
        },
        {
            id: 'status',
            header: t('brain.keys.colStatus'),
            cell: ({ row }) =>
                row.original.revoked ? (
                    <Badge variant="destructive">
                        {t('brain.keys.revoked')}
                    </Badge>
                ) : (
                    <Badge variant="success" className="gap-1.5">
                        <span
                            className="size-1.5 rounded-full bg-success"
                            aria-hidden
                        />
                        {t('brain.keys.active')}
                    </Badge>
                ),
        },
    ];

    return (
        <>
            <Head title={t('brain.keys.title')} />
            <PageLayout
                filters={
                    hideListChrome ? undefined : (
                        <DataTableToolbar
                            search={search}
                            onSearchChange={setSearch}
                            searchPlaceholder={t(
                                'brain.keys.searchPlaceholder',
                            )}
                        />
                    )
                }
            >
                {!is_owner ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.keys.ownerOnly')}
                    </p>
                ) : isListEmpty ? (
                    <PageEmptyState
                        icon={hasActiveFilters ? SearchX : KeyRound}
                        title={
                            <EmptyStatus>
                                {hasActiveFilters
                                    ? t('emptyState.keys.filteredTitle')
                                    : t('emptyState.keys.title')}
                            </EmptyStatus>
                        }
                        description={
                            hasActiveFilters
                                ? t('emptyState.keys.filteredDescription')
                                : t('emptyState.keys.description')
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
                        data={filteredKeys}
                        getRowId={(key) => key.id}
                    />
                )}
            </PageLayout>
        </>
    );
}
