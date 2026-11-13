import { Head, Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

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

export default function BrainProposals({
    proposals,
    hasPerimeter,
    statusFilter,
}: Props) {
    const { t } = useTranslation();

    const setStatusFilter = (nextStatus: string) => {
        router.get(
            '/brain/proposals',
            { status: nextStatus },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={t('brain.proposals.title')} />
            <PageLayout description={t('brain.proposals.description')}>
                <div className="flex flex-wrap items-end justify-between gap-3 pb-2">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('brain.proposals.title')}
                        </h1>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {(['open', 'accepted', 'rejected'] as const).map(
                            (status) => (
                                <Button
                                    key={status}
                                    type="button"
                                    size="sm"
                                    variant={
                                        statusFilter === status
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => setStatusFilter(status)}
                                >
                                    {t(`brain.proposals.status.${status}`)}
                                </Button>
                            ),
                        )}
                    </div>
                </div>

                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : proposals.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.proposals.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('brain.proposals.colTitle')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.proposals.colAction')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.proposals.colStatus')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.proposals.colPath')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.proposals.colCreated')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {proposals.map((proposal) => (
                                <TableRow key={proposal.id}>
                                    <TableCell className="font-medium">
                                        <Link
                                            href={`/brain/proposals/${proposal.id}`}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {proposal.title}
                                        </Link>
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
                                    <TableCell>
                                        <Badge variant="secondary">
                                            {t(
                                                `brain.proposals.status.${proposal.status}`,
                                                {
                                                    defaultValue:
                                                        proposal.status,
                                                },
                                            )}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {proposal.path}
                                    </TableCell>
                                    <TableCell className="text-sm text-muted-foreground">
                                        {proposal.created_at ?? '—'}
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

BrainProposals.layout = {
    breadcrumbs: [
        {
            title: 'Proposals',
            href: '/brain/proposals',
        },
    ],
};
