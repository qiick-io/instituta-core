import { Link } from '@inertiajs/react';
import {
    Activity,
    Database,
    FolderOpen,
    HeartPulse,
    Inbox,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import type {
    DashboardActivityEvent,
    DashboardOverviewMetrics,
} from '@/components/dashboard/dashboard-types';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatRelativeTime } from '@/lib/relative-time';
import { cn } from '@/lib/utils';
import activityRoutes from '@/routes/activity';
import brainRoutes from '@/routes/brain';
import proposalRoutes from '@/routes/brain/proposals';

/**
 * The dashboard overview: four key numbers, then content per area and sources, then open
 * proposals and the latest activity. Layout copied from externa-core's overview panel
 * (app-ui `docs/dashboard.md`): flat cards in a 12-column grid, tables capped at `max-h-64`.
 */
export function DashboardOverviewPanel({
    contentStats,
    areaCounts,
    sources,
    proposals,
    apiKeys,
    latestActivity,
    health,
}: DashboardOverviewMetrics) {
    const { t, i18n } = useTranslation();
    const syncedSourcesCount = sources.filter(
        (source) => source.sync_status === 'ok',
    ).length;
    const relativeTime = (isoDate: string | null): string =>
        isoDate === null
            ? '—'
            : formatRelativeTime(Date.parse(isoDate) / 1000, i18n.language);

    return (
        <div className="flex flex-col gap-4">
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-12">
                <StatCard
                    className="xl:col-span-3"
                    label={t('dashboard.statItems')}
                    value={contentStats.items_count.toLocaleString()}
                    headline={t('dashboard.statItemsHeadline', {
                        sections: contentStats.sections_count.toLocaleString(),
                        links: contentStats.links_count.toLocaleString(),
                    })}
                    context={t('dashboard.statItemsContext', {
                        covered: contentStats.areas_covered,
                        total: contentStats.areas_total,
                    })}
                />
                <StatCard
                    className="xl:col-span-3"
                    label={t('dashboard.statSources')}
                    value={sources.length.toLocaleString()}
                    headline={t('dashboard.statSourcesHeadline', {
                        count: syncedSourcesCount,
                    })}
                    context={
                        health === null
                            ? t('dashboard.statSourcesContext')
                            : t('dashboard.statSourcesHealth', {
                                  errors: health.error_count,
                                  warnings: health.warning_count,
                              })
                    }
                />
                <StatCard
                    className="xl:col-span-3"
                    label={t('dashboard.statOpenProposals')}
                    value={proposals.open_count.toLocaleString()}
                    context={t('dashboard.statOpenProposalsContext')}
                />
                {apiKeys !== null ? (
                    <StatCard
                        className="xl:col-span-3"
                        label={t('dashboard.statApiKeys')}
                        value={apiKeys.active_count.toLocaleString()}
                        headline={t('dashboard.statApiKeysHeadline', {
                            count: apiKeys.expiring_soon_count,
                        })}
                        context={t('dashboard.statApiKeysContext')}
                    />
                ) : (
                    <StatCard
                        className="xl:col-span-3"
                        label={t('dashboard.statAreas')}
                        value={`${contentStats.areas_covered} / ${contentStats.areas_total}`}
                        context={t('dashboard.statAreasContext')}
                    />
                )}
            </section>

            <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <DashboardCard
                    className="xl:col-span-4"
                    title={t('dashboard.areasTitle')}
                    description={t('dashboard.areasDescription')}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('dashboard.colArea')}</TableHead>
                                <TableHead className="w-[6rem] text-right tabular-nums">
                                    {t('dashboard.colItems')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody className="[&_td]:h-10">
                            {areaCounts.map((row) => (
                                <TableRow key={row.area}>
                                    <TableCell className="max-w-0 min-w-0">
                                        <Link
                                            href={brainRoutes.tree.url({
                                                query: { area: row.area },
                                            })}
                                            className="block truncate font-medium hover:underline"
                                        >
                                            {t(`brain.areas.${row.area}`, {
                                                defaultValue: row.area,
                                            })}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.count.toLocaleString()}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {areaCounts.length === 0 ? (
                                <EmptyRow colSpan={2}>
                                    <PageEmptyState
                                        icon={FolderOpen}
                                        title={t('dashboard.areasEmptyTitle')}
                                        description={t(
                                            'dashboard.areasEmptyDescription',
                                        )}
                                        compact
                                        className="border-0"
                                    />
                                </EmptyRow>
                            ) : null}
                        </TableBody>
                    </Table>
                </DashboardCard>

                <DashboardCard
                    className="xl:col-span-8"
                    title={t('dashboard.sourcesTitle')}
                    description={t('dashboard.sourcesDescription')}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('brain.sources.colName')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.sources.colStatus')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.sources.colSynced')}
                                </TableHead>
                                <TableHead className="w-[6rem] text-right tabular-nums">
                                    {t('dashboard.colItems')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody className="[&_td]:h-10">
                            {sources.map((source) => (
                                <TableRow key={source.id}>
                                    <TableCell className="max-w-0 min-w-0">
                                        <div className="truncate font-medium">
                                            {source.name}
                                        </div>
                                        <div className="truncate font-mono text-xs text-muted-foreground">
                                            {source.provider}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                source.sync_status === 'failed'
                                                    ? 'destructive'
                                                    : source.sync_status ===
                                                        'ok'
                                                      ? 'success'
                                                      : 'secondary'
                                            }
                                        >
                                            {t(
                                                `brain.sources.status.${source.sync_status}`,
                                                {
                                                    defaultValue:
                                                        source.sync_status,
                                                },
                                            )}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {relativeTime(source.last_synced_at)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {source.items_count.toLocaleString()}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {sources.length === 0 ? (
                                <EmptyRow colSpan={4}>
                                    <PageEmptyState
                                        icon={Database}
                                        title={t('emptyState.sources.title')}
                                        description={t(
                                            'emptyState.sources.description',
                                        )}
                                        compact
                                        className="border-0"
                                    />
                                </EmptyRow>
                            ) : null}
                        </TableBody>
                    </Table>
                </DashboardCard>
            </section>

            <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <DashboardCard
                    className={
                        latestActivity === null
                            ? 'xl:col-span-12'
                            : 'xl:col-span-6'
                    }
                    title={t('dashboard.proposalsTitle')}
                    description={t('dashboard.proposalsDescription')}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('brain.proposals.colTitle')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.proposals.colAction')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('brain.proposals.colCreated')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody className="[&_td]:h-10">
                            {proposals.recent.map((proposal) => (
                                <TableRow key={proposal.id}>
                                    <TableCell className="max-w-0 min-w-0">
                                        <Link
                                            href={proposalRoutes.show.url(
                                                proposal.id,
                                            )}
                                            className="block truncate font-medium hover:underline"
                                        >
                                            {proposal.title ?? proposal.id}
                                        </Link>
                                        {proposal.area !== null ? (
                                            <div className="truncate font-mono text-xs text-muted-foreground">
                                                {proposal.area}
                                            </div>
                                        ) : null}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="outline">
                                            {t(
                                                `brain.proposals.action.${proposal.action}`,
                                                {
                                                    defaultValue:
                                                        proposal.action,
                                                },
                                            )}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right text-muted-foreground">
                                        {relativeTime(proposal.created_at)}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {proposals.recent.length === 0 ? (
                                <EmptyRow colSpan={3}>
                                    <PageEmptyState
                                        icon={Inbox}
                                        title={t(
                                            'dashboard.proposalsEmptyTitle',
                                        )}
                                        description={t(
                                            'dashboard.proposalsEmptyDescription',
                                        )}
                                        compact
                                        className="border-0"
                                    />
                                </EmptyRow>
                            ) : null}
                        </TableBody>
                    </Table>
                </DashboardCard>

                {latestActivity !== null ? (
                    <DashboardCard
                        className="xl:col-span-6"
                        title={t('dashboard.activityTitle')}
                        description={t('dashboard.activityDescription')}
                        footer={
                            <Link
                                href={activityRoutes.index.url()}
                                className="text-sm text-muted-foreground hover:underline"
                            >
                                {t('dashboard.activityViewAll')}
                            </Link>
                        }
                    >
                        <ActivityTable
                            events={latestActivity}
                            relativeTime={relativeTime}
                        />
                    </DashboardCard>
                ) : null}
            </section>

            {health !== null ? (
                <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                    <DashboardCard
                        className="xl:col-span-12"
                        title={t('dashboard.healthTitle')}
                        description={t('dashboard.healthDescription')}
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-[7rem]">
                                        {t('dashboard.colSeverity')}
                                    </TableHead>
                                    <TableHead>
                                        {t('dashboard.colIssue')}
                                    </TableHead>
                                    <TableHead>
                                        {t('dashboard.colFix')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {health.issues.map((issue, index) => (
                                    <TableRow
                                        key={`${issue.code}-${issue.path}-${index}`}
                                    >
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    issue.severity === 'error'
                                                        ? 'destructive'
                                                        : 'warning'
                                                }
                                            >
                                                {t(
                                                    `dashboard.health.${issue.code}`,
                                                    {
                                                        defaultValue:
                                                            issue.code,
                                                    },
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="max-w-[28rem]">
                                            {issue.stable_key !== null ? (
                                                <Link
                                                    href={brainRoutes.reading.url(
                                                        issue.stable_key,
                                                    )}
                                                    className="block truncate font-mono text-xs underline-offset-4 hover:underline"
                                                >
                                                    {issue.path}
                                                </Link>
                                            ) : (
                                                <div className="truncate font-mono text-xs">
                                                    {issue.path}
                                                </div>
                                            )}
                                            <div className="text-sm">
                                                {issue.message}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {issue.suggested_fix}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {health.issues.length === 0 ? (
                                    <EmptyRow colSpan={3}>
                                        <PageEmptyState
                                            icon={HeartPulse}
                                            title={t(
                                                'dashboard.healthEmptyTitle',
                                            )}
                                            description={t(
                                                'dashboard.healthEmptyDescription',
                                            )}
                                            compact
                                            className="border-0"
                                        />
                                    </EmptyRow>
                                ) : null}
                            </TableBody>
                        </Table>
                    </DashboardCard>
                </section>
            ) : null}
        </div>
    );
}

/**
 * A flat dashboard card: title, one-line description, then a table capped at `max-h-64`
 * that scrolls (and is a named, focusable region for keyboard users).
 */
function DashboardCard({
    title,
    description,
    className,
    footer,
    children,
}: {
    title: string;
    description: string;
    className?: string;
    footer?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card className={cn(className)}>
            <CardHeader className="pb-3">
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <div
                    role="region"
                    aria-label={title}
                    className="max-h-64 overflow-auto"
                    tabIndex={0}
                >
                    {children}
                </div>
                {footer}
            </CardContent>
        </Card>
    );
}

function EmptyRow({
    colSpan,
    children,
}: {
    colSpan: number;
    children: ReactNode;
}) {
    return (
        <TableRow>
            <TableCell className="p-3" colSpan={colSpan}>
                {children}
            </TableCell>
        </TableRow>
    );
}

function ActivityTable({
    events,
    relativeTime,
}: {
    events: DashboardActivityEvent[];
    relativeTime: (isoDate: string | null) => string;
}) {
    const { t } = useTranslation();

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>{t('activity.colDescription')}</TableHead>
                    <TableHead>{t('activity.colCauser')}</TableHead>
                    <TableHead className="text-right">
                        {t('activity.colWhen')}
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody className="[&_td]:h-10">
                {events.map((event) => (
                    <TableRow key={event.id}>
                        <TableCell className="max-w-0 min-w-0">
                            <div className="truncate font-medium">
                                {event.description}
                            </div>
                            {event.log_name !== null ? (
                                <div className="truncate font-mono text-xs text-muted-foreground">
                                    {event.log_name}
                                </div>
                            ) : null}
                        </TableCell>
                        <TableCell className="max-w-[10rem] truncate">
                            {event.causer?.name ?? t('activity.system')}
                        </TableCell>
                        <TableCell className="text-right text-muted-foreground">
                            {relativeTime(event.created_at)}
                        </TableCell>
                    </TableRow>
                ))}
                {events.length === 0 ? (
                    <EmptyRow colSpan={3}>
                        <PageEmptyState
                            icon={Activity}
                            title={t('emptyState.activity.title')}
                            description={t('emptyState.activity.description')}
                            compact
                            className="border-0"
                        />
                    </EmptyRow>
                ) : null}
            </TableBody>
        </Table>
    );
}
