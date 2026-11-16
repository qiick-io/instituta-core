import type { ColumnDef } from '@tanstack/react-table';
import { Head, router } from '@inertiajs/react';
import { ClipboardList, SearchX } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ActivityViewTabs } from '@/components/activity/activity-view-tabs';
import {
    DataTable,
    dataTableExpandColumn,
} from '@/components/admin/data-table';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { TruncatedText } from '@/components/admin/truncated-text';
import { EmptyStatus } from '@/components/empty-status';
import { FilterSearch } from '@/components/layout/page-header';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useDebouncedServerSearch } from '@/hooks/use-debounced-server-search';
import { listPerPageQuery } from '@/lib/list-per-page';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import type { BreadcrumbItem, Paginated } from '@/types';
import { index as activityIndex } from '@/routes/activity';
import AppLayout from '@/layouts/app-layout';

type CauserOption = {
    id: number;
    name: string;
    email: string;
};

type ActivityRow = {
    id: number;
    event: string | null;
    description: string | null;
    log_name: string | null;
    created_at: string | null;
    causer: { id: number; name: string; email: string } | null;
    subject: { type: string; id: string | number; label: string } | null;
    changes: Record<string, unknown>;
    properties: {
        ip: string | null;
        user_agent: string | null;
        meta: Record<string, unknown>;
    };
};

type Filters = {
    search: string;
    causer_id: number | null;
    event: string;
    log_name: string;
    date_from: string;
    date_to: string;
    subject_type: string;
    subject_id: string;
};

type Props = {
    is_owner: boolean;
    activityLogs: LaravelPaginated<ActivityRow> | Paginated<ActivityRow>;
    causers: CauserOption[];
    events: string[];
    logNames: string[];
    filters: Filters;
    retention_days: number;
};

/**
 * Organisation-owner Activity (audit) log: list-page chrome with filters and
 * expandable row details. Route stays `/activity` (product audit surface).
 * Pattern copied from excubia-app / externa-core `admin/activity-logs`.
 */
export default function ActivityIndex({
    is_owner,
    activityLogs: activityLogsProp,
    causers,
    events,
    logNames,
    filters,
    retention_days,
}: Props) {
    const { t } = useTranslation();
    const activityLogs = normalizePaginated(activityLogsProp);
    const [search, setSearch] = useState(filters.search ?? '');
    const [causerId, setCauserId] = useState<number | null>(
        filters.causer_id ?? null,
    );
    const [event, setEvent] = useState(filters.event ?? '');
    const [logName, setLogName] = useState(filters.log_name ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('activity.title'),
                href: activityIndex.url(),
            },
        ],
        [t],
    );

    const layoutProps = { breadcrumbs };

    const visit = useCallback(
        (overrides: Partial<Filters> = {}) => {
            const nextSearch =
                overrides.search !== undefined ? overrides.search : search;
            const nextCauserId =
                overrides.causer_id !== undefined
                    ? overrides.causer_id
                    : causerId;
            const nextEvent =
                overrides.event !== undefined ? overrides.event : event;
            const nextLogName =
                overrides.log_name !== undefined ? overrides.log_name : logName;
            const nextDateFrom =
                overrides.date_from !== undefined
                    ? overrides.date_from
                    : dateFrom;
            const nextDateTo =
                overrides.date_to !== undefined ? overrides.date_to : dateTo;

            router.get(
                activityIndex.url(),
                {
                    search: nextSearch || undefined,
                    causer_id: nextCauserId || undefined,
                    event: nextEvent || undefined,
                    log_name: nextLogName || undefined,
                    date_from: nextDateFrom || undefined,
                    date_to: nextDateTo || undefined,
                    per_page: listPerPageQuery(activityLogs.per_page),
                },
                { preserveState: true, preserveScroll: true },
            );
        },
        [
            search,
            causerId,
            event,
            logName,
            dateFrom,
            dateTo,
            activityLogs.per_page,
        ],
    );

    const onDebouncedSearch = useCallback(
        (value: string): void => {
            visit({ search: value });
        },
        [visit],
    );

    useDebouncedServerSearch(search, filters.search ?? '', onDebouncedSearch);

    const clearFilters = (): void => {
        setSearch('');
        setCauserId(null);
        setEvent('');
        setLogName('');
        setDateFrom('');
        setDateTo('');
        visit({
            search: '',
            causer_id: null,
            event: '',
            log_name: '',
            date_from: '',
            date_to: '',
            subject_type: '',
            subject_id: '',
        });
    };

    const formatDate = (value: string | null): string =>
        value ? new Date(value).toLocaleString() : '—';

    /**
     * Events logged with their key as description (`source.scanner_skip`) read as a sentence,
     * with the count they carry; other descriptions show as logged.
     */
    const descriptionLabel = (row: ActivityRow): string => {
        if (!row.description) {
            return '—';
        }

        if (row.event === null || row.description !== row.event) {
            return row.description;
        }

        const count = row.properties.meta.count;

        return t(`activity.descriptions.${row.event}`, {
            count: typeof count === 'number' ? count : undefined,
            defaultValue: eventLabel(row.event),
        });
    };

    const eventLabel = (eventName: string | null | undefined): string => {
        if (!eventName) {
            return '—';
        }

        return t(`activity.events.${eventName}`, {
            defaultValue: eventName,
        });
    };

    const logNameLabel = (name: string): string =>
        t(`activity.logs.${name}`, { defaultValue: name });

    const renderChanges = (row: ActivityRow): string => {
        const hasChanges = row.changes && Object.keys(row.changes).length > 0;
        const meta = row.properties?.meta ?? {};
        const hasMeta = Object.keys(meta).length > 0;

        if (!hasChanges && !hasMeta) {
            return t('activity.noChanges');
        }

        if (hasChanges && hasMeta) {
            return JSON.stringify({ changes: row.changes, meta }, null, 2);
        }

        return JSON.stringify(hasChanges ? row.changes : meta, null, 2);
    };

    const hasActiveFilters =
        search.trim() !== '' ||
        causerId !== null ||
        event !== '' ||
        logName !== '' ||
        dateFrom !== '' ||
        dateTo !== '';

    const rows = activityLogs.data ?? [];
    const isListEmpty = rows.length === 0;
    const hideListChrome = !is_owner;

    const columns: ColumnDef<ActivityRow>[] = [
        dataTableExpandColumn<ActivityRow>({
            expandLabel: t('activity.expand'),
            collapseLabel: t('activity.collapse'),
        }),
        {
            id: 'created_at',
            header: t('activity.colWhen'),
            meta: { cellClassName: 'text-sm whitespace-nowrap tabular-nums' },
            cell: ({ row }) => formatDate(row.original.created_at),
        },
        {
            id: 'user',
            header: t('activity.colCauser'),
            cell: ({ row }) =>
                row.original.causer ? (
                    <div className="text-sm">
                        <div>{row.original.causer.name}</div>
                        <div className="text-xs text-muted-foreground">
                            {row.original.causer.email}
                        </div>
                    </div>
                ) : (
                    <span className="text-sm text-muted-foreground">
                        {t('activity.system')}
                    </span>
                ),
        },
        {
            id: 'action',
            header: t('activity.colEvent'),
            cell: ({ row }) => (
                <Badge variant="secondary">
                    {eventLabel(row.original.event)}
                </Badge>
            ),
        },
        {
            id: 'description',
            header: t('activity.colDescription'),
            meta: { cellClassName: 'max-w-xs text-sm' },
            cell: ({ row }) => (
                <TruncatedText text={descriptionLabel(row.original)} />
            ),
        },
        {
            id: 'subject',
            header: t('activity.colSubject'),
            meta: { cellClassName: 'text-sm' },
            cell: ({ row }) =>
                row.original.subject ? (
                    <>
                        {row.original.subject.type} {row.original.subject.label}
                    </>
                ) : (
                    '—'
                ),
        },
    ];

    return (
        <AppLayout {...layoutProps}>
            <Head title={t('activity.title')} />
            <ActivityViewTabs view="audit-log" />
            <PageLayout
                description={t('activity.description', {
                    days: retention_days,
                })}
                filters={
                    hideListChrome ? undefined : (
                        <>
                            <FilterSearch
                                value={search}
                                onChange={setSearch}
                                placeholder={t('activity.searchPlaceholder')}
                                className="max-w-[12rem] min-w-[8rem] flex-none"
                            />
                            <NativeSelect
                                value={causerId ? String(causerId) : ''}
                                onChange={(changeEvent) => {
                                    const next =
                                        changeEvent.target.value === ''
                                            ? null
                                            : Number(changeEvent.target.value);
                                    setCauserId(next);
                                    visit({ causer_id: next });
                                }}
                                className="max-w-[12rem] min-w-[8rem] shrink-0"
                                aria-label={t('activity.filters.causer')}
                            >
                                <option value="">
                                    {t('activity.filters.allCausers')}
                                </option>
                                {causers.map((causer) => (
                                    <option
                                        key={causer.id}
                                        value={String(causer.id)}
                                    >
                                        {causer.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            <NativeSelect
                                value={event}
                                onChange={(changeEvent) => {
                                    setEvent(changeEvent.target.value);
                                    visit({
                                        event: changeEvent.target.value,
                                    });
                                }}
                                className="max-w-[10rem] min-w-[7rem] shrink-0"
                                aria-label={t('activity.filters.event')}
                            >
                                <option value="">
                                    {t('activity.filters.allEvents')}
                                </option>
                                {events.map((eventName) => (
                                    <option key={eventName} value={eventName}>
                                        {eventLabel(eventName)}
                                    </option>
                                ))}
                            </NativeSelect>
                            <NativeSelect
                                value={logName}
                                onChange={(changeEvent) => {
                                    setLogName(changeEvent.target.value);
                                    visit({
                                        log_name: changeEvent.target.value,
                                    });
                                }}
                                className="max-w-[10rem] min-w-[7rem] shrink-0"
                                aria-label={t('activity.filters.logName')}
                            >
                                <option value="">
                                    {t('activity.filters.allLogs')}
                                </option>
                                {logNames.map((name) => (
                                    <option key={name} value={name}>
                                        {logNameLabel(name)}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Input
                                type="date"
                                value={dateFrom}
                                onChange={(changeEvent) => {
                                    setDateFrom(changeEvent.target.value);
                                    visit({
                                        date_from: changeEvent.target.value,
                                    });
                                }}
                                className="h-control w-auto shrink-0"
                                aria-label={t('activity.filters.dateFrom')}
                            />
                            <Input
                                type="date"
                                value={dateTo}
                                onChange={(changeEvent) => {
                                    setDateTo(changeEvent.target.value);
                                    visit({
                                        date_to: changeEvent.target.value,
                                    });
                                }}
                                className="h-control w-auto shrink-0"
                                aria-label={t('activity.filters.dateTo')}
                            />
                            {hasActiveFilters ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={clearFilters}
                                >
                                    {t('common.clearFilters')}
                                </Button>
                            ) : null}
                        </>
                    )
                }
            >
                {!is_owner ? (
                    <p className="text-sm text-muted-foreground">
                        {t('activity.ownerOnly')}
                    </p>
                ) : isListEmpty ? (
                    <PageEmptyState
                        icon={hasActiveFilters ? SearchX : ClipboardList}
                        title={
                            <EmptyStatus>
                                {hasActiveFilters
                                    ? t('emptyState.activity.filteredTitle')
                                    : t('emptyState.activity.title')}
                            </EmptyStatus>
                        }
                        description={
                            hasActiveFilters
                                ? t('emptyState.activity.filteredDescription')
                                : t('emptyState.activity.description')
                        }
                        action={
                            hasActiveFilters ? (
                                <Button type="button" onClick={clearFilters}>
                                    {t('common.clearFilters')}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <DataTable
                        columns={columns}
                        data={rows}
                        getRowId={(row) => String(row.id)}
                        pagination={activityLogs}
                        renderExpandedRow={(row) => (
                            <div className="space-y-3 py-2">
                                <div className="grid gap-2 text-sm sm:grid-cols-2">
                                    <div>
                                        <span className="text-muted-foreground">
                                            {t('activity.colLog')}:
                                        </span>{' '}
                                        {logNameLabel(
                                            row.log_name ?? 'default',
                                        )}
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">
                                            {t('activity.ip')}:
                                        </span>{' '}
                                        {row.properties.ip ?? '—'}
                                    </div>
                                </div>
                                <pre className="overflow-x-auto rounded-md bg-muted p-3 text-xs">
                                    {renderChanges(row)}
                                </pre>
                            </div>
                        )}
                        emptyState={
                            <span className="text-muted-foreground">
                                {t('activity.empty')}
                            </span>
                        }
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
