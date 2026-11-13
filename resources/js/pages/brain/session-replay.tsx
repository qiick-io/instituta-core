import type { ColumnDef } from '@tanstack/react-table';
import { Head, router, setLayoutProps } from '@inertiajs/react';
import { History, SearchX } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { TruncatedText } from '@/components/admin/truncated-text';
import { EmptyStatus } from '@/components/empty-status';
import { FilterSearch } from '@/components/layout/page-header';
import {
    PageLayout,
    TablePaginationFooter,
    hasTablePaginationFooter,
    type TablePaginationState,
} from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { sessionReplay } from '@/routes/brain';
import type { BreadcrumbItem } from '@/types';

type PrincipalOption = {
    id: string;
    name: string;
};

type CallRow = {
    id: string;
    tool_or_endpoint: string;
    scope_type: string;
    scope_id: string;
    organisation_id: string | null;
    company_id: string | null;
    product_id: string | null;
    item_ids: string[];
    token_estimate: number | null;
    called_at: string | null;
    principal: { id: string; name: string } | null;
    api_key: {
        id: string;
        name: string;
        display_prefix: string;
    } | null;
    agent_session_id: string | null;
};

type Filters = {
    search: string;
    principal_id: string;
    api_key_id: string;
    agent_session_id: string;
    tool_or_endpoint: string;
    date_from: string;
    date_to: string;
};

type PaginatedCalls = {
    data: CallRow[];
    meta?: TablePaginationState & {
        from?: number | null;
        to?: number | null;
    };
    links?: TablePaginationState['links'];
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
    from?: number | null;
    to?: number | null;
};

type Props = {
    is_owner: boolean;
    calls: PaginatedCalls;
    principals: PrincipalOption[];
    tools: string[];
    filters: Filters;
};

function paginationMeta(calls: PaginatedCalls): TablePaginationState {
    if (calls.meta) {
        return {
            current_page: calls.meta.current_page,
            last_page: calls.meta.last_page,
            per_page: calls.meta.per_page,
            total: calls.meta.total,
            links: calls.meta.links ?? calls.links ?? [],
        };
    }

    return {
        current_page: calls.current_page ?? 1,
        last_page: calls.last_page ?? 1,
        per_page: calls.per_page ?? 20,
        total: calls.total ?? 0,
        links: calls.links ?? [],
    };
}

function visitWith(
    filters: Filters,
    overrides: Partial<Filters> & { page?: number; per_page?: number },
): void {
    router.get(
        sessionReplay.url(),
        {
            ...filters,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true },
    );
}

/**
 * Session replay: contract_calls list + sheet detail (kickoff / list-page chrome).
 * Metadata only — no bodies or secrets.
 */
export default function BrainSessionReplay({
    is_owner,
    calls,
    principals,
    tools,
    filters,
}: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState(filters.search ?? '');
    const [apiKeyId, setApiKeyId] = useState(filters.api_key_id ?? '');
    const [agentSessionId, setAgentSessionId] = useState(
        filters.agent_session_id ?? '',
    );
    const [selected, setSelected] = useState<CallRow | null>(null);
    const pagination = paginationMeta(calls);

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('brain.sessionReplay.title'),
                href: sessionReplay.url(),
            },
        ],
        [t],
    );

    setLayoutProps({ breadcrumbs });

    const visit = (
        overrides: Partial<Filters> & { page?: number; per_page?: number } = {},
    ): void => {
        visitWith(filters, {
            search:
                overrides.search !== undefined
                    ? overrides.search
                    : search || filters.search,
            api_key_id:
                overrides.api_key_id !== undefined
                    ? overrides.api_key_id
                    : apiKeyId || filters.api_key_id,
            agent_session_id:
                overrides.agent_session_id !== undefined
                    ? overrides.agent_session_id
                    : agentSessionId || filters.agent_session_id,
            ...overrides,
            page: overrides.page ?? 1,
        });
    };

    const columns: ColumnDef<CallRow>[] = [
        {
            id: 'when',
            header: t('brain.sessionReplay.colWhen'),
            meta: {
                cellClassName:
                    'whitespace-nowrap text-sm text-muted-foreground',
            },
            cell: ({ row }) =>
                row.original.called_at
                    ? new Date(row.original.called_at).toLocaleString()
                    : '—',
        },
        {
            id: 'tool',
            header: t('brain.sessionReplay.colTool'),
            meta: { cellClassName: 'font-mono text-xs max-w-[16rem]' },
            cell: ({ row }) => (
                <TruncatedText text={row.original.tool_or_endpoint} />
            ),
        },
        {
            id: 'principal',
            header: t('brain.sessionReplay.colPrincipal'),
            cell: ({ row }) => row.original.principal?.name ?? '—',
        },
        {
            id: 'scope',
            header: t('brain.sessionReplay.colScope'),
            meta: { cellClassName: 'font-mono text-xs text-muted-foreground' },
            cell: ({ row }) =>
                `${row.original.scope_type}:${row.original.scope_id}`,
        },
        {
            id: 'actions',
            header: t('brain.sessionReplay.colActions'),
            meta: { cellClassName: 'w-[1%] text-right' },
            cell: ({ row }) => (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setSelected(row.original)}
                >
                    {t('brain.sessionReplay.view')}
                </Button>
            ),
        },
    ];

    const hasActiveFilters =
        Boolean(filters.search) ||
        Boolean(filters.principal_id) ||
        Boolean(filters.tool_or_endpoint) ||
        Boolean(filters.api_key_id) ||
        Boolean(filters.agent_session_id) ||
        Boolean(filters.date_from) ||
        Boolean(filters.date_to);

    return (
        <>
            <Head title={t('brain.sessionReplay.title')} />
            <PageLayout
                description={t('brain.sessionReplay.description')}
                filters={
                    is_owner ? (
                        <div className="flex flex-wrap items-end gap-2">
                            <form
                                className="contents"
                                onSubmit={(submitEvent) => {
                                    submitEvent.preventDefault();
                                    visit({
                                        search,
                                        api_key_id: apiKeyId,
                                        agent_session_id: agentSessionId,
                                    });
                                }}
                            >
                                <FilterSearch
                                    value={search}
                                    onChange={setSearch}
                                    placeholder={t(
                                        'brain.sessionReplay.searchPlaceholder',
                                    )}
                                />
                            </form>
                            <div className="space-y-1">
                                <Label htmlFor="principal_id">
                                    {t('brain.sessionReplay.filters.principal')}
                                </Label>
                                <Select
                                    value={filters.principal_id || '__all__'}
                                    onValueChange={(value) =>
                                        visit({
                                            principal_id:
                                                value === '__all__'
                                                    ? ''
                                                    : value,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="principal_id"
                                        className="w-48"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">
                                            {t(
                                                'brain.sessionReplay.filters.all',
                                            )}
                                        </SelectItem>
                                        {principals.map((principal) => (
                                            <SelectItem
                                                key={principal.id}
                                                value={principal.id}
                                            >
                                                {principal.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="tool_or_endpoint">
                                    {t('brain.sessionReplay.filters.tool')}
                                </Label>
                                <Select
                                    value={
                                        filters.tool_or_endpoint || '__all__'
                                    }
                                    onValueChange={(value) =>
                                        visit({
                                            tool_or_endpoint:
                                                value === '__all__'
                                                    ? ''
                                                    : value,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="tool_or_endpoint"
                                        className="w-48"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">
                                            {t(
                                                'brain.sessionReplay.filters.all',
                                            )}
                                        </SelectItem>
                                        {tools.map((tool) => (
                                            <SelectItem key={tool} value={tool}>
                                                {tool}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="api_key_id">
                                    {t('brain.sessionReplay.fieldKey')}
                                </Label>
                                <Input
                                    id="api_key_id"
                                    value={apiKeyId}
                                    onChange={(event) =>
                                        setApiKeyId(event.target.value)
                                    }
                                    onBlur={() =>
                                        visit({ api_key_id: apiKeyId })
                                    }
                                    className="w-40 font-mono text-xs"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="agent_session_id">
                                    {t('brain.sessionReplay.fieldSession')}
                                </Label>
                                <Input
                                    id="agent_session_id"
                                    value={agentSessionId}
                                    onChange={(event) =>
                                        setAgentSessionId(event.target.value)
                                    }
                                    onBlur={() =>
                                        visit({
                                            agent_session_id: agentSessionId,
                                        })
                                    }
                                    className="w-40 font-mono text-xs"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="date_from">
                                    {t('brain.sessionReplay.filters.dateFrom')}
                                </Label>
                                <Input
                                    id="date_from"
                                    type="date"
                                    value={filters.date_from}
                                    onChange={(event) =>
                                        visit({
                                            date_from: event.target.value,
                                        })
                                    }
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="date_to">
                                    {t('brain.sessionReplay.filters.dateTo')}
                                </Label>
                                <Input
                                    id="date_to"
                                    type="date"
                                    value={filters.date_to}
                                    onChange={(event) =>
                                        visit({
                                            date_to: event.target.value,
                                        })
                                    }
                                />
                            </div>
                        </div>
                    ) : undefined
                }
            >
                {!is_owner ? (
                    <PageEmptyState
                        icon={SearchX}
                        title={
                            <EmptyStatus>
                                {t('brain.sessionReplay.ownerOnly')}
                            </EmptyStatus>
                        }
                        description={t('brain.sessionReplay.description')}
                        compact
                    />
                ) : calls.data.length === 0 ? (
                    <PageEmptyState
                        icon={hasActiveFilters ? SearchX : History}
                        title={
                            <EmptyStatus>
                                {t('brain.sessionReplay.empty')}
                            </EmptyStatus>
                        }
                        description={t('brain.sessionReplay.hint')}
                        compact
                    />
                ) : (
                    <div className="space-y-4">
                        <DataTable
                            columns={columns}
                            data={calls.data}
                            getRowId={(row) => row.id}
                        />
                        {hasTablePaginationFooter(pagination) ? (
                            <TablePaginationFooter pagination={pagination} />
                        ) : null}
                    </div>
                )}
            </PageLayout>

            <Sheet
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelected(null);
                    }
                }}
            >
                <SheetContent className="overflow-y-auto sm:max-w-lg">
                    <SheetHeader>
                        <SheetTitle>
                            {t('brain.sessionReplay.detailTitle')}
                        </SheetTitle>
                        <SheetDescription>
                            {t('brain.sessionReplay.detailHint')}
                        </SheetDescription>
                    </SheetHeader>
                    {selected ? (
                        <dl className="mt-6 space-y-3 text-sm">
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('brain.sessionReplay.colTool')}
                                </dt>
                                <dd className="font-mono text-xs">
                                    {selected.tool_or_endpoint}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('brain.sessionReplay.colScope')}
                                </dt>
                                <dd className="font-mono text-xs">
                                    {selected.scope_type}:{selected.scope_id}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('brain.sessionReplay.fieldKey')}
                                </dt>
                                <dd>
                                    {selected.api_key
                                        ? `${selected.api_key.name} (${selected.api_key.display_prefix}…)`
                                        : '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('brain.sessionReplay.fieldSession')}
                                </dt>
                                <dd className="font-mono text-xs">
                                    {selected.agent_session_id ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('brain.sessionReplay.fieldTokens')}
                                </dt>
                                <dd>{selected.token_estimate ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('brain.sessionReplay.fieldItemIds')}
                                </dt>
                                <dd className="font-mono text-xs break-all">
                                    {selected.item_ids.length > 0
                                        ? selected.item_ids.join(', ')
                                        : '—'}
                                </dd>
                            </div>
                        </dl>
                    ) : null}
                </SheetContent>
            </Sheet>
        </>
    );
}
