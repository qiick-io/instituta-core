import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FilterSearch } from '@/components/layout/page-header';
import {
    PageLayout,
    TablePaginationFooter,
    TablePanel,
    hasTablePaginationFooter,
    type TablePaginationState,
} from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { agentCalls as sessionReplay } from '@/routes/activity';
import AppLayout from '@/layouts/app-layout';
import { History } from 'lucide-react';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
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
 * Session replay: inspect contract_calls without content or secrets.
 */
export default function BrainSessionReplay({
    is_owner,
    calls,
    principals,
    tools,
    filters,
}: Props) {
    const { t } = useTranslation();
    const layoutProps = {
        breadcrumbs: [
            {
                title: t('brain.nav.sessionReplay'),
                href: sessionReplay.url(),
            },
        ],
    };
    const [search, setSearch] = useState(filters.search ?? '');
    const [apiKeyId, setApiKeyId] = useState(filters.api_key_id ?? '');
    const [agentSessionId, setAgentSessionId] = useState(
        filters.agent_session_id ?? '',
    );
    const [selected, setSelected] = useState<CallRow | null>(null);
    const pagination = paginationMeta(calls);

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

    return (
        <AppLayout {...layoutProps}>
            <Head title={t('brain.sessionReplay.title')} />
            <PageLayout
                description={t('brain.sessionReplay.description')}
                filters={
                    is_owner ? (
                        <div className="flex flex-wrap items-center gap-2">
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
                            <Select
                                value={filters.principal_id || '__all__'}
                                onValueChange={(value) =>
                                    visit({
                                        principal_id:
                                            value === '__all__' ? '' : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    id="principal_id"
                                    aria-label={t(
                                        'brain.sessionReplay.filters.principal',
                                    )}
                                    className="w-40"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__all__">
                                        {t(
                                            'brain.sessionReplay.filters.allPrincipals',
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
                            <Select
                                value={filters.tool_or_endpoint || '__all__'}
                                onValueChange={(value) =>
                                    visit({
                                        tool_or_endpoint:
                                            value === '__all__' ? '' : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    id="tool_or_endpoint"
                                    aria-label={t(
                                        'brain.sessionReplay.filters.tool',
                                    )}
                                    className="w-40"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__all__">
                                        {t(
                                            'brain.sessionReplay.filters.allTools',
                                        )}
                                    </SelectItem>
                                    {tools.map((tool) => (
                                        <SelectItem key={tool} value={tool}>
                                            {tool}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                id="api_key_id"
                                aria-label={t('brain.sessionReplay.fieldKey')}
                                placeholder={t('brain.sessionReplay.fieldKey')}
                                value={apiKeyId}
                                onChange={(event) =>
                                    setApiKeyId(event.target.value)
                                }
                                onBlur={() => visit({ api_key_id: apiKeyId })}
                                className="w-36 font-mono text-xs"
                            />
                            <Input
                                id="agent_session_id"
                                aria-label={t(
                                    'brain.sessionReplay.fieldSession',
                                )}
                                placeholder={t(
                                    'brain.sessionReplay.fieldSession',
                                )}
                                value={agentSessionId}
                                onChange={(event) =>
                                    setAgentSessionId(event.target.value)
                                }
                                onBlur={() =>
                                    visit({
                                        agent_session_id: agentSessionId,
                                    })
                                }
                                className="w-36 font-mono text-xs"
                            />
                            <Input
                                id="date_from"
                                aria-label={t(
                                    'brain.sessionReplay.filters.dateFrom',
                                )}
                                type="date"
                                className="w-36"
                                value={filters.date_from}
                                onChange={(event) =>
                                    visit({
                                        date_from: event.target.value,
                                    })
                                }
                            />
                            <Input
                                id="date_to"
                                aria-label={t(
                                    'brain.sessionReplay.filters.dateTo',
                                )}
                                type="date"
                                className="w-36"
                                value={filters.date_to}
                                onChange={(event) =>
                                    visit({
                                        date_to: event.target.value,
                                    })
                                }
                            />
                        </div>
                    ) : undefined
                }
            >
                {!is_owner ? (
                    <PageEmptyState
                        icon={History}
                        title={t('brain.sessionReplay.ownerOnly')}
                        description={t('brain.sessionReplay.description')}
                    />
                ) : (
                    <div className="space-y-4">
                        <TablePanel>
                            {calls.data.length === 0 ? (
                                <PageEmptyState
                                    icon={History}
                                    title={
                                        <EmptyStatus>
                                            {t('brain.sessionReplay.empty')}
                                        </EmptyStatus>
                                    }
                                    description={t('brain.sessionReplay.hint')}
                                    compact
                                    className="border-0"
                                />
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t(
                                                    'brain.sessionReplay.colWhen',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'brain.sessionReplay.colTool',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'brain.sessionReplay.colPrincipal',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'brain.sessionReplay.colScope',
                                                )}
                                            </TableHead>
                                            <TableHead className="w-[1%] text-right">
                                                {t(
                                                    'brain.sessionReplay.colActions',
                                                )}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {calls.data.map((call) => (
                                            <TableRow key={call.id}>
                                                <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                                    {call.called_at
                                                        ? new Date(
                                                              call.called_at,
                                                          ).toLocaleString()
                                                        : '—'}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">
                                                    {call.tool_or_endpoint}
                                                </TableCell>
                                                <TableCell>
                                                    {call.principal?.name ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs text-muted-foreground">
                                                    {call.scope_type}:
                                                    {call.scope_id}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setSelected(call)
                                                        }
                                                    >
                                                        {t(
                                                            'brain.sessionReplay.view',
                                                        )}
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                            {hasTablePaginationFooter(pagination) ? (
                                <TablePaginationFooter
                                    pagination={pagination}
                                />
                            ) : null}
                        </TablePanel>
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
        </AppLayout>
    );
}
