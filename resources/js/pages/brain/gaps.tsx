import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, router } from '@inertiajs/react';
import { CircleHelp, FilePenLine, SearchX, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { TruncatedText } from '@/components/admin/truncated-text';
import { DataTable } from '@/components/admin/data-table';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import {
    ProposeChangeDrawer,
    type ProposeChangeTarget,
} from '@/components/brain/propose-change-drawer';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { ReviewTabs } from '@/components/brain/review-tabs';
import { index as reviewIndex } from '@/routes/review';
import { show as reading } from '@/routes/explorer';
import {
    destroy as gapsDestroy,
    index as gapsIndex,
} from '@/routes/review/gaps';
import type { BreadcrumbItem } from '@/types';

type RelatedItem = {
    item: string;
    stable_key: string;
    path: string;
    title: string;
    anchor: string | null;
};

type GapRow = {
    id: string;
    kind: string;
    status: string;
    summary: string;
    question: string | null;
    related: RelatedItem[];
    reporter: string | null;
    proposal_id: string | null;
    created_at: string | null;
};

type Props = {
    gaps: GapRow[];
    hasPerimeter: boolean;
    isOwner: boolean;
    statusFilter: string;
};

/**
 * Gaps and contradictions agents reported (#229), for the scope's owners: each one is turned
 * into a proposal (the propose drawer, prefilled and linked to the report) or dismissed.
 * Layout copied from the proposals list: status filter right, DataTable, row actions far right.
 */
export default function BrainGaps({
    gaps,
    hasPerimeter,
    isOwner,
    statusFilter,
}: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [proposeTarget, setProposeTarget] =
        useState<ProposeChangeTarget | null>(null);
    const [pendingDismiss, setPendingDismiss] = useState<GapRow | null>(null);
    const [isDismissing, setIsDismissing] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            // The view (Proposals / Gaps) is the tab under the header, not a breadcrumb (#303).
            { title: t('review.title'), href: reviewIndex.url() },
        ],
        [t],
    );

    const setStatusFilter = (nextStatus: string): void => {
        if (nextStatus === '' || nextStatus === statusFilter) {
            return;
        }

        router.get(
            gapsIndex.url(),
            { status: nextStatus },
            { preserveState: true, replace: true },
        );
    };

    const filteredGaps = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') {
            return gaps;
        }

        return gaps.filter(
            (gap) =>
                gap.summary.toLowerCase().includes(needle) ||
                (gap.question ?? '').toLowerCase().includes(needle) ||
                gap.related.some((related) =>
                    related.path.toLowerCase().includes(needle),
                ),
        );
    }, [gaps, search]);

    const hasActiveFilters = search.trim() !== '';
    const isListEmpty = filteredGaps.length === 0;
    const hideListChrome =
        !hasPerimeter || !isOwner || (gaps.length === 0 && !hasActiveFilters);

    const proposeFromGap = (gap: GapRow): void => {
        const firstRelated = gap.related[0];

        setProposeTarget({
            mode: 'create',
            area: firstRelated ? firstRelated.path.split('/')[0] : null,
            title: gap.summary,
            draftRequest: [gap.summary, gap.question]
                .filter((part) => part !== null && part !== '')
                .join('\n'),
            gapReportId: gap.id,
        });
    };

    const columns: ColumnDef<GapRow>[] = [
        {
            id: 'summary',
            header: t('brain.gaps.colSummary'),
            meta: { cellClassName: 'max-w-[20rem]' },
            cell: ({ row }) => (
                <div className="flex flex-col gap-0.5">
                    <TruncatedText
                        className="font-medium"
                        text={row.original.summary}
                    />
                    {row.original.question ? (
                        <TruncatedText
                            className="text-xs text-muted-foreground"
                            text={row.original.question}
                        />
                    ) : null}
                </div>
            ),
        },
        {
            id: 'kind',
            header: t('brain.gaps.colKind'),
            cell: ({ row }) => (
                <Badge variant="outline">
                    {t(`brain.gaps.kind.${row.original.kind}`, {
                        defaultValue: row.original.kind,
                    })}
                </Badge>
            ),
        },
        {
            id: 'related',
            header: t('brain.gaps.colRelated'),
            meta: { cellClassName: 'max-w-[16rem]' },
            cell: ({ row }) =>
                row.original.related.length === 0 ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    <ul className="flex flex-col gap-0.5 font-mono text-xs">
                        {row.original.related.map((related) => (
                            <li key={`${related.item}#${related.anchor ?? ''}`}>
                                <Link
                                    href={reading.url(related.stable_key)}
                                    className="underline-offset-4 hover:underline"
                                >
                                    <TruncatedText
                                        text={
                                            related.anchor
                                                ? `${related.path}#${related.anchor}`
                                                : related.path
                                        }
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                ),
        },
        {
            id: 'reporter',
            header: t('brain.gaps.colReporter'),
            meta: { cellClassName: 'text-sm text-muted-foreground' },
            cell: ({ row }) => row.original.reporter ?? '—',
        },
        {
            id: 'created',
            header: t('brain.gaps.colCreated'),
            meta: { cellClassName: 'text-sm text-muted-foreground' },
            cell: ({ row }) => row.original.created_at ?? '—',
        },
        {
            id: 'actions',
            header: t('brain.gaps.colActions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
            },
            cell: ({ row }) =>
                row.original.status !== 'open' ? null : (
                    <div className="flex justify-end gap-1">
                        <HeaderIconButton
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            label={t('brain.gaps.propose')}
                            onClick={() => proposeFromGap(row.original)}
                        >
                            <FilePenLine className="size-3.5" />
                        </HeaderIconButton>
                        <HeaderIconButton
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            className="text-destructive hover:text-destructive"
                            label={t('brain.gaps.dismiss')}
                            onClick={() => setPendingDismiss(row.original)}
                        >
                            <X className="size-3.5" />
                        </HeaderIconButton>
                    </div>
                ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('brain.gaps.title')} />
            <ReviewTabs view="gaps" />
            <PageLayout
                description={t('review.gapsDescription')}
                filters={
                    hideListChrome ? undefined : (
                        <DataTableToolbar
                            search={search}
                            onSearchChange={setSearch}
                            searchPlaceholder={t(
                                'brain.gaps.searchPlaceholder',
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
                            aria-label={t('brain.gaps.colStatus')}
                        >
                            {(['open', 'converted', 'dismissed'] as const).map(
                                (status) => (
                                    <ToggleGroupItem
                                        key={status}
                                        value={status}
                                    >
                                        {t(`brain.gaps.status.${status}`)}
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
                ) : !isOwner ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.gaps.ownersOnly')}
                    </p>
                ) : isListEmpty ? (
                    <PageEmptyState
                        icon={hasActiveFilters ? SearchX : CircleHelp}
                        title={
                            <EmptyStatus>
                                {hasActiveFilters
                                    ? t('emptyState.gaps.filteredTitle')
                                    : t('emptyState.gaps.title')}
                            </EmptyStatus>
                        }
                        description={
                            hasActiveFilters
                                ? t('emptyState.gaps.filteredDescription')
                                : t('emptyState.gaps.description')
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
                        data={filteredGaps}
                        getRowId={(gap) => gap.id}
                    />
                )}
            </PageLayout>

            <Drawer
                direction="right"
                open={proposeTarget !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) {
                        setProposeTarget(null);
                    }
                }}
            >
                <ProposeChangeDrawer
                    target={proposeTarget}
                    open={proposeTarget !== null}
                    onCancel={() => setProposeTarget(null)}
                />
            </Drawer>

            <ConfirmDestructiveDialog
                open={pendingDismiss !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) {
                        setPendingDismiss(null);
                    }
                }}
                title={t('brain.gaps.confirmDismissTitle')}
                description={t('brain.gaps.confirmDismissDescription')}
                confirmLabel={t('brain.gaps.dismiss')}
                confirming={isDismissing}
                onConfirm={() => {
                    if (pendingDismiss === null) {
                        return;
                    }

                    setIsDismissing(true);
                    router.delete(gapsDestroy.url(pendingDismiss.id), {
                        preserveScroll: true,
                        onFinish: () => setIsDismissing(false),
                        onSuccess: () => setPendingDismiss(null),
                    });
                }}
            />
        </AppLayout>
    );
}
