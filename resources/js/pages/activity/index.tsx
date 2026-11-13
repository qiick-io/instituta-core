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
import { Badge } from '@/components/ui/badge';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { listPerPageQuery } from '@/lib/list-per-page';

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

type PaginatedActivities = {
    data: ActivityRow[];
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
    activityLogs: PaginatedActivities;
    causers: CauserOption[];
    events: string[];
    logNames: string[];
    filters: Filters;
    retention_days: number;
};

function paginationMeta(
    activityLogs: PaginatedActivities,
): TablePaginationState {
    if (activityLogs.meta) {
        return {
            current_page: activityLogs.meta.current_page,
            last_page: activityLogs.meta.last_page,
            per_page: activityLogs.meta.per_page,
            total: activityLogs.meta.total,
            links: activityLogs.meta.links ?? activityLogs.links ?? [],
        };
    }

    return {
        current_page: activityLogs.current_page ?? 1,
        last_page: activityLogs.last_page ?? 1,
        per_page: activityLogs.per_page ?? 20,
        total: activityLogs.total ?? 0,
        links: activityLogs.links ?? [],
    };
}

/**
 * Organisation-owner Activity (audit) log with filters and a detail drawer.
 */
export default function ActivityIndex({
    is_owner,
    activityLogs,
    causers,
    events,
    logNames,
    filters,
    retention_days,
}: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState(filters.search ?? '');
    const [selected, setSelected] = useState<ActivityRow | null>(null);
    const meta = paginationMeta(activityLogs);
    const rows = activityLogs.data ?? [];

    const visit = (overrides: Partial<Filters> = {}) => {
        router.get(
            '/activity',
            {
                search:
                    overrides.search !== undefined
                        ? overrides.search || undefined
                        : search || undefined,
                causer_id:
                    overrides.causer_id !== undefined
                        ? overrides.causer_id || undefined
                        : filters.causer_id || undefined,
                event:
                    overrides.event !== undefined
                        ? overrides.event || undefined
                        : filters.event || undefined,
                log_name:
                    overrides.log_name !== undefined
                        ? overrides.log_name || undefined
                        : filters.log_name || undefined,
                date_from:
                    overrides.date_from !== undefined
                        ? overrides.date_from || undefined
                        : filters.date_from || undefined,
                date_to:
                    overrides.date_to !== undefined
                        ? overrides.date_to || undefined
                        : filters.date_to || undefined,
                subject_type:
                    overrides.subject_type !== undefined
                        ? overrides.subject_type || undefined
                        : filters.subject_type || undefined,
                subject_id:
                    overrides.subject_id !== undefined
                        ? overrides.subject_id || undefined
                        : filters.subject_id || undefined,
                per_page: listPerPageQuery(meta.per_page),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={t('activity.title')} />
            <PageLayout
                description={t('activity.description', {
                    days: retention_days,
                })}
                filters={
                    is_owner ? (
                        <div className="flex flex-wrap items-end gap-2">
                            <form
                                className="contents"
                                onSubmit={(submitEvent) => {
                                    submitEvent.preventDefault();
                                    visit({ search });
                                }}
                            >
                                <FilterSearch
                                    value={search}
                                    onChange={setSearch}
                                    placeholder={t(
                                        'activity.searchPlaceholder',
                                    )}
                                />
                            </form>
                            <div className="space-y-1">
                                <Label htmlFor="activity-log-name">
                                    {t('activity.filters.logName')}
                                </Label>
                                <Select
                                    value={filters.log_name || '__all__'}
                                    onValueChange={(value) =>
                                        visit({
                                            log_name:
                                                value === '__all__'
                                                    ? ''
                                                    : value,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="activity-log-name"
                                        className="w-[10rem]"
                                    >
                                        <SelectValue
                                            placeholder={t(
                                                'activity.filters.all',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">
                                            {t('activity.filters.all')}
                                        </SelectItem>
                                        {logNames.map((logName) => (
                                            <SelectItem
                                                key={logName}
                                                value={logName}
                                            >
                                                {logName}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="activity-event">
                                    {t('activity.filters.event')}
                                </Label>
                                <Select
                                    value={filters.event || '__all__'}
                                    onValueChange={(value) =>
                                        visit({
                                            event:
                                                value === '__all__'
                                                    ? ''
                                                    : value,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="activity-event"
                                        className="w-[12rem]"
                                    >
                                        <SelectValue
                                            placeholder={t(
                                                'activity.filters.all',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">
                                            {t('activity.filters.all')}
                                        </SelectItem>
                                        {events.map((eventName) => (
                                            <SelectItem
                                                key={eventName}
                                                value={eventName}
                                            >
                                                {eventName}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="activity-causer">
                                    {t('activity.filters.causer')}
                                </Label>
                                <Select
                                    value={
                                        filters.causer_id
                                            ? String(filters.causer_id)
                                            : '__all__'
                                    }
                                    onValueChange={(value) =>
                                        visit({
                                            causer_id:
                                                value === '__all__'
                                                    ? null
                                                    : Number(value),
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="activity-causer"
                                        className="w-[12rem]"
                                    >
                                        <SelectValue
                                            placeholder={t(
                                                'activity.filters.all',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">
                                            {t('activity.filters.all')}
                                        </SelectItem>
                                        {causers.map((causer) => (
                                            <SelectItem
                                                key={causer.id}
                                                value={String(causer.id)}
                                            >
                                                {causer.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="activity-date-from">
                                    {t('activity.filters.dateFrom')}
                                </Label>
                                <Input
                                    id="activity-date-from"
                                    type="date"
                                    className="w-[10rem]"
                                    value={filters.date_from || ''}
                                    onChange={(event) =>
                                        visit({
                                            date_from: event.target.value,
                                        })
                                    }
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="activity-date-to">
                                    {t('activity.filters.dateTo')}
                                </Label>
                                <Input
                                    id="activity-date-to"
                                    type="date"
                                    className="w-[10rem]"
                                    value={filters.date_to || ''}
                                    onChange={(event) =>
                                        visit({ date_to: event.target.value })
                                    }
                                />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    visit({
                                        search: '',
                                        causer_id: null,
                                        event: '',
                                        log_name: '',
                                        date_from: '',
                                        date_to: '',
                                        subject_type: '',
                                        subject_id: '',
                                    })
                                }
                            >
                                {t('common.clearFilters')}
                            </Button>
                        </div>
                    ) : null
                }
            >
                {!is_owner ? (
                    <p className="text-sm text-muted-foreground">
                        {t('activity.ownerOnly')}
                    </p>
                ) : rows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('activity.empty')}
                    </p>
                ) : (
                    <TablePanel
                        footer={
                            hasTablePaginationFooter(meta) ? (
                                <TablePaginationFooter pagination={meta} />
                            ) : null
                        }
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('activity.colWhen')}
                                    </TableHead>
                                    <TableHead>
                                        {t('activity.colLog')}
                                    </TableHead>
                                    <TableHead>
                                        {t('activity.colEvent')}
                                    </TableHead>
                                    <TableHead>
                                        {t('activity.colCauser')}
                                    </TableHead>
                                    <TableHead>
                                        {t('activity.colSubject')}
                                    </TableHead>
                                    <TableHead className="w-[1%]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="text-sm whitespace-nowrap text-muted-foreground tabular-nums">
                                            {row.created_at
                                                ? new Date(
                                                      row.created_at,
                                                  ).toLocaleString()
                                                : '—'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="secondary">
                                                {row.log_name ?? 'default'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {row.event ?? row.description}
                                        </TableCell>
                                        <TableCell>
                                            {row.causer?.name ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {row.subject
                                                ? `${row.subject.type}: ${row.subject.label}`
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => setSelected(row)}
                                            >
                                                {t('activity.view')}
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TablePanel>
                )}
            </PageLayout>

            <Sheet
                open={selected !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) {
                        setSelected(null);
                    }
                }}
            >
                <SheetContent className="overflow-y-auto sm:max-w-lg">
                    <SheetHeader>
                        <SheetTitle>{t('activity.detailTitle')}</SheetTitle>
                        <SheetDescription>
                            {selected?.event ?? selected?.description}
                        </SheetDescription>
                    </SheetHeader>
                    {selected ? (
                        <div className="mt-4 space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">
                                    {t('activity.colWhen')}:{' '}
                                </span>
                                {selected.created_at
                                    ? new Date(
                                          selected.created_at,
                                      ).toLocaleString()
                                    : '—'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">
                                    {t('activity.colLog')}:{' '}
                                </span>
                                {selected.log_name ?? 'default'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">
                                    {t('activity.colCauser')}:{' '}
                                </span>
                                {selected.causer
                                    ? `${selected.causer.name} <${selected.causer.email}>`
                                    : '—'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">
                                    {t('activity.colSubject')}:{' '}
                                </span>
                                {selected.subject
                                    ? `${selected.subject.type} #${selected.subject.id} (${selected.subject.label})`
                                    : '—'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">
                                    {t('activity.ip')}:{' '}
                                </span>
                                {selected.properties.ip ?? '—'}
                            </p>
                            <div>
                                <p className="mb-1 text-muted-foreground">
                                    {t('activity.meta')}
                                </p>
                                <pre className="overflow-x-auto rounded-md border bg-muted/40 p-3 text-xs">
                                    {JSON.stringify(
                                        {
                                            changes: selected.changes,
                                            meta: selected.properties.meta,
                                        },
                                        null,
                                        2,
                                    )}
                                </pre>
                            </div>
                        </div>
                    ) : null}
                </SheetContent>
            </Sheet>
        </>
    );
}

ActivityIndex.layout = {
    breadcrumbs: [
        {
            title: 'Activity',
            href: '/activity',
        },
    ],
};
