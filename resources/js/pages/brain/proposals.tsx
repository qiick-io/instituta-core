import type { ColumnDef } from '@tanstack/react-table';
import { Head, router } from '@inertiajs/react';
import { FilePenLine, SearchX } from 'lucide-react';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import brainRoutes from '@/routes/brain';
import type { BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';

type ProposalRow = {
    id: string;
    status: string;
    action: string;
    stable_key: string;
    title: string;
    path: string;
    area: string | null;
    materialisation_status: string | null;
    created_at: string | null;
};

type Props = {
    proposals: ProposalRow[];
    hasPerimeter: boolean;
    statusFilter: string;
    canDecide: boolean;
};

/**
 * Write proposals index: status filter, DataTable, row opens the detail page.
 */
export default function BrainProposals({
    proposals,
    hasPerimeter,
    statusFilter,
}: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('brain.proposals.title'),
                href: brainRoutes.proposals.index.url(),
            },
        ],
        [t],
    );

    const layoutProps = { breadcrumbs };

    const setStatusFilter = (nextStatus: string): void => {
        if (nextStatus === '' || nextStatus === statusFilter) {
            return;
        }

        router.get(
            brainRoutes.proposals.index.url(),
            { status: nextStatus },
            { preserveState: true, replace: true },
        );
    };

    const filteredProposals = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') {
            return proposals;
        }

        return proposals.filter(
            (proposal) =>
                proposal.title.toLowerCase().includes(needle) ||
                proposal.path.toLowerCase().includes(needle) ||
                proposal.stable_key.toLowerCase().includes(needle),
        );
    }, [proposals, search]);

    const hasActiveFilters = search.trim() !== '';
    const isListEmpty = filteredProposals.length === 0;
    const hideListChrome =
        !hasPerimeter || (proposals.length === 0 && !hasActiveFilters);

    const columns: ColumnDef<ProposalRow>[] = [
        {
            id: 'title',
            header: t('brain.proposals.colTitle'),
            meta: { cellClassName: 'max-w-[16rem] font-medium' },
            cell: ({ row }) => <TruncatedText text={row.original.title} />,
        },
        {
            id: 'action',
            header: t('brain.proposals.colAction'),
            cell: ({ row }) => (
                <Badge variant="outline">
                    {t(`brain.proposals.action.${row.original.action}`, {
                        defaultValue: row.original.action,
                    })}
                </Badge>
            ),
        },
        {
            id: 'status',
            header: t('brain.proposals.colStatus'),
            cell: ({ row }) => (
                <Badge variant="secondary">
                    {t(`brain.proposals.status.${row.original.status}`, {
                        defaultValue: row.original.status,
                    })}
                </Badge>
            ),
        },
        {
            id: 'path',
            header: t('brain.proposals.colPath'),
            meta: { cellClassName: 'max-w-[14rem] font-mono text-xs' },
            cell: ({ row }) => <TruncatedText text={row.original.path} />,
        },
        {
            id: 'created',
            header: t('brain.proposals.colCreated'),
            meta: { cellClassName: 'text-sm text-muted-foreground' },
            cell: ({ row }) => row.original.created_at ?? '—',
        },
    ];

    return (
        <AppLayout {...layoutProps}>
            <Head title={t('brain.proposals.title')} />
            <PageLayout
                filters={
                    hideListChrome ? undefined : (
                        <DataTableToolbar
                            search={search}
                            onSearchChange={setSearch}
                            searchPlaceholder={t(
                                'brain.proposals.searchPlaceholder',
                            )}
                        />
                    )
                }
                filtersRight={
                    hideListChrome ? null : (
                        <ToggleGroup
                            type="single"
                            value={statusFilter}
                            onValueChange={setStatusFilter}
                            variant="outline"
                            aria-label={t('brain.proposals.colStatus')}
                        >
                            {(['open', 'accepted', 'rejected'] as const).map(
                                (status) => (
                                    <ToggleGroupItem
                                        key={status}
                                        value={status}
                                    >
                                        {t(`brain.proposals.status.${status}`)}
                                    </ToggleGroupItem>
                                ),
                            )}
                        </ToggleGroup>
                    )
                }
            >
                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : isListEmpty ? (
                    <PageEmptyState
                        icon={hasActiveFilters ? SearchX : FilePenLine}
                        title={
                            <EmptyStatus>
                                {hasActiveFilters
                                    ? t('emptyState.proposals.filteredTitle')
                                    : t('emptyState.proposals.title')}
                            </EmptyStatus>
                        }
                        description={
                            hasActiveFilters
                                ? t('emptyState.proposals.filteredDescription')
                                : t('emptyState.proposals.description')
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
                        data={filteredProposals}
                        getRowId={(proposal) => proposal.id}
                        onRowClick={(proposal) =>
                            router.visit(
                                brainRoutes.proposals.show.url(proposal.id),
                            )
                        }
                        getRowProps={() => ({ className: 'cursor-pointer' })}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
