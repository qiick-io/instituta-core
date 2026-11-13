import { Head, Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type ProposalDetail = {
    id: string;
    status: string;
    action: string;
    stable_key: string;
    title: string;
    path: string;
    area: string | null;
    body: string | null;
    materialisation_status: string | null;
    remote_branch: string | null;
    remote_pr_url: string | null;
    materialised_commit: string | null;
    materialisation_error: string | null;
    created_at: string | null;
};

type Props = {
    proposal: ProposalDetail;
    canDecide: boolean;
};

export default function BrainProposalShow({ proposal, canDecide }: Props) {
    const { t } = useTranslation();

    const decide = (status: 'accepted' | 'rejected') => {
        router.patch(`/brain/proposals/${proposal.id}`, { status });
    };

    return (
        <>
            <Head title={proposal.title} />
            <PageLayout description={t('brain.proposals.showDescription')}>
                <div className="flex flex-wrap items-start justify-between gap-3 pb-4">
                    <div className="space-y-2">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {proposal.title}
                        </h1>
                        <div className="flex flex-wrap gap-2">
                            <Badge variant="secondary">
                                {t(
                                    `brain.proposals.status.${proposal.status}`,
                                    {
                                        defaultValue: proposal.status,
                                    },
                                )}
                            </Badge>
                            <Badge variant="outline">
                                {t(
                                    `brain.proposals.action.${proposal.action}`,
                                    {
                                        defaultValue: proposal.action,
                                    },
                                )}
                            </Badge>
                        </div>
                    </div>
                    <Button type="button" variant="outline" asChild>
                        <Link href="/brain/proposals">
                            {t('brain.proposals.backToList')}
                        </Link>
                    </Button>
                </div>

                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-muted-foreground">
                            {t('brain.proposals.fieldStableKey')}
                        </dt>
                        <dd className="font-mono text-xs">
                            {proposal.stable_key}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">
                            {t('brain.proposals.fieldPath')}
                        </dt>
                        <dd className="font-mono text-xs">{proposal.path}</dd>
                    </div>
                    {proposal.area ? (
                        <div>
                            <dt className="text-muted-foreground">
                                {t('brain.proposals.fieldArea')}
                            </dt>
                            <dd>{proposal.area}</dd>
                        </div>
                    ) : null}
                    {proposal.materialisation_status ? (
                        <div>
                            <dt className="text-muted-foreground">
                                {t('brain.proposals.fieldMaterialisation')}
                            </dt>
                            <dd>
                                {t(
                                    `brain.proposals.materialisation.${proposal.materialisation_status}`,
                                    {
                                        defaultValue:
                                            proposal.materialisation_status,
                                    },
                                )}
                            </dd>
                        </div>
                    ) : null}
                    {proposal.remote_branch ? (
                        <div>
                            <dt className="text-muted-foreground">
                                {t('brain.proposals.fieldBranch')}
                            </dt>
                            <dd className="font-mono text-xs">
                                {proposal.remote_branch}
                            </dd>
                        </div>
                    ) : null}
                    {proposal.remote_pr_url ? (
                        <div className="sm:col-span-2">
                            <dt className="text-muted-foreground">
                                {t('brain.proposals.fieldPullRequest')}
                            </dt>
                            <dd>
                                <a
                                    href={proposal.remote_pr_url}
                                    className="underline-offset-4 hover:underline"
                                    rel="noreferrer"
                                    target="_blank"
                                >
                                    {proposal.remote_pr_url}
                                </a>
                            </dd>
                        </div>
                    ) : null}
                    {proposal.materialisation_error ? (
                        <div className="sm:col-span-2">
                            <dt className="text-muted-foreground">
                                {t('brain.proposals.fieldMaterialisationError')}
                            </dt>
                            <dd className="text-destructive">
                                {proposal.materialisation_error}
                            </dd>
                        </div>
                    ) : null}
                </dl>

                <div className="mt-6 space-y-2">
                    <h2 className="text-sm font-medium">
                        {t('brain.proposals.bodyHeading')}
                    </h2>
                    {proposal.body ? (
                        <pre className="overflow-x-auto rounded-xl border border-sidebar-border/70 bg-muted/30 p-4 font-mono text-xs dark:border-sidebar-border">
                            {proposal.body}
                        </pre>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {t('brain.proposals.bodyEmpty')}
                        </p>
                    )}
                </div>

                {canDecide ? (
                    <div className="mt-6 flex flex-wrap gap-2">
                        <Button
                            type="button"
                            onClick={() => decide('accepted')}
                        >
                            {t('brain.proposals.accept')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => decide('rejected')}
                        >
                            {t('brain.proposals.reject')}
                        </Button>
                    </div>
                ) : null}
            </PageLayout>
        </>
    );
}

BrainProposalShow.layout = {
    breadcrumbs: [
        {
            title: 'Proposals',
            href: '/brain/proposals',
        },
        {
            title: 'Proposal',
            href: '#',
        },
    ],
};
