import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

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

export default function BrainSources({ sources, hasPerimeter }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('brain.sources.title')} />
            <PageLayout description={t('brain.sources.description')}>
                <div className="space-y-1 pb-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('brain.sources.title')}
                    </h1>
                </div>

                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : sources.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.sources.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('brain.sources.colName')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.sources.colProvider')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.sources.colStatus')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.sources.colCommit')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.sources.colSynced')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.sources.colReport')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sources.map((source) => (
                                <TableRow key={source.id}>
                                    <TableCell className="font-medium">
                                        {source.name}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="outline">
                                            {source.provider}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                source.sync_status === 'failed'
                                                    ? 'destructive'
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
                                    <TableCell className="font-mono text-xs">
                                        {source.last_synced_commit
                                            ? source.last_synced_commit.slice(
                                                  0,
                                                  7,
                                              )
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="text-sm text-muted-foreground">
                                        {source.last_synced_at ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {source.last_sync_report ? (
                                            <Collapsible>
                                                <CollapsibleTrigger className="text-sm underline-offset-4 hover:underline">
                                                    {t(
                                                        'brain.sources.showReport',
                                                    )}
                                                </CollapsibleTrigger>
                                                <CollapsibleContent>
                                                    <pre className="mt-2 max-w-md overflow-x-auto rounded-md bg-muted/40 p-2 font-mono text-xs">
                                                        {JSON.stringify(
                                                            source.last_sync_report,
                                                            null,
                                                            2,
                                                        )}
                                                    </pre>
                                                </CollapsibleContent>
                                            </Collapsible>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </PageLayout>
        </>
    );
}

BrainSources.layout = {
    breadcrumbs: [
        {
            title: 'Sources',
            href: '/brain/sources',
        },
    ],
};
