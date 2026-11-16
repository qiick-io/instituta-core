import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import {
    index as proposalsIndex,
    show as proposalsShow,
    update as proposalsUpdate,
} from '@/routes/brain/proposals';
import AppLayout from '@/layouts/app-layout';

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
    section_anchor: string | null;
    decision_note: string | null;
    base_content_hash: string | null;
    base_commit: string | null;
    merged_at: string | null;
    proposed_by: {
        name: string | null;
        type: string | null;
        key_name: string | null;
    };
    overlapping_open_proposals: string[];
};

type DiffLine = {
    type: 'same' | 'added' | 'removed';
    text: string;
};

type ProposalDiff = {
    current: string | null;
    proposed: string | null;
    is_too_large: boolean;
    lines: DiffLine[];
};

type Props = {
    proposal: ProposalDetail;
    diff: ProposalDiff | null;
    canDecide: boolean;
};

const DIFF_LINE_PREFIX: Record<DiffLine['type'], string> = {
    same: ' ',
    added: '+',
    removed: '-',
};

/**
 * Current (indexed) content against the proposed one. Every changed line carries a + / -
 * sign, so colour never carries the change alone.
 */
function ProposalDiffView({ diff }: { diff: ProposalDiff }) {
    const { t } = useTranslation();

    if (diff.is_too_large) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('brain.proposals.diffTooLarge')}
            </p>
        );
    }

    if (diff.lines.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('brain.proposals.bodyEmpty')}
            </p>
        );
    }

    return (
        <div
            role="region"
            aria-label={t('brain.proposals.diffHeading')}
            tabIndex={0}
            className="max-h-[32rem] overflow-auto rounded-xl border border-sidebar-border/70 font-mono text-xs dark:border-sidebar-border"
        >
            {diff.lines.map((line, index) => (
                <div
                    key={index}
                    className={cn(
                        'flex gap-3 px-3 py-0.5 whitespace-pre-wrap',
                        line.type === 'added' && 'bg-success/10',
                        line.type === 'removed' && 'bg-destructive/10',
                    )}
                >
                    <span
                        aria-hidden="true"
                        className="w-3 shrink-0 text-muted-foreground select-none"
                    >
                        {DIFF_LINE_PREFIX[line.type]}
                    </span>
                    <span className="sr-only">
                        {line.type === 'same'
                            ? ''
                            : t(`brain.proposals.diffLine.${line.type}`)}
                    </span>
                    <span className="min-w-0 break-words">
                        {line.text === '' ? '\u00a0' : line.text}
                    </span>
                </div>
            ))}
        </div>
    );
}

export default function BrainProposalShow({
    proposal,
    diff,
    canDecide,
}: Props) {
    const { t } = useTranslation();
    const layoutProps = {
        breadcrumbs: [
            {
                title: t('brain.nav.proposals'),
                href: proposalsIndex.url(),
            },
            {
                title: proposal.title,
                href: '#',
            },
        ],
    };
    const [decisionNote, setDecisionNote] = useState('');

    const decide = (status: 'accepted' | 'rejected') => {
        router.patch(proposalsUpdate.url(proposal.id), {
            status,
            note: decisionNote.trim() === '' ? null : decisionNote,
        });
    };

    return (
        <AppLayout {...layoutProps}>
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
                        <Link href={proposalsIndex.url()}>
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
                        <dd className="font-mono text-xs">
                            {proposal.path}
                            {proposal.section_anchor
                                ? ` #${proposal.section_anchor}`
                                : ''}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">
                            {t('brain.proposals.fieldProposedBy')}
                        </dt>
                        <dd>
                            {proposal.proposed_by.name ?? '—'}
                            {proposal.proposed_by.type ? (
                                <Badge variant="outline" className="ml-2">
                                    {t(
                                        `brain.proposals.principalType.${proposal.proposed_by.type}`,
                                        {
                                            defaultValue:
                                                proposal.proposed_by.type,
                                        },
                                    )}
                                </Badge>
                            ) : null}
                            {proposal.proposed_by.key_name ? (
                                <span className="ml-2 text-xs text-muted-foreground">
                                    {t('brain.proposals.viaKey', {
                                        key: proposal.proposed_by.key_name,
                                    })}
                                </span>
                            ) : null}
                        </dd>
                    </div>
                    {proposal.base_commit || proposal.base_content_hash ? (
                        <div>
                            <dt className="text-muted-foreground">
                                {t('brain.proposals.fieldBase')}
                            </dt>
                            <dd className="font-mono text-xs">
                                {(
                                    proposal.base_commit ??
                                    proposal.base_content_hash ??
                                    ''
                                ).slice(0, 12)}
                            </dd>
                        </div>
                    ) : null}
                    {proposal.decision_note ? (
                        <div className="sm:col-span-2">
                            <dt className="text-muted-foreground">
                                {t('brain.proposals.fieldDecisionNote')}
                            </dt>
                            <dd className="whitespace-pre-wrap">
                                {proposal.decision_note}
                            </dd>
                        </div>
                    ) : null}
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

                {proposal.overlapping_open_proposals.length > 0 ? (
                    <div className="mt-6 space-y-1 rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm">
                        <p className="font-medium">
                            {t('brain.proposals.overlappingTitle', {
                                count: proposal.overlapping_open_proposals
                                    .length,
                            })}
                        </p>
                        <ul className="flex flex-wrap gap-2">
                            {proposal.overlapping_open_proposals.map(
                                (overlappingId) => (
                                    <li key={overlappingId}>
                                        <Link
                                            href={proposalsShow.url(
                                                overlappingId,
                                            )}
                                            className="font-mono text-xs underline-offset-4 hover:underline"
                                        >
                                            {overlappingId}
                                        </Link>
                                    </li>
                                ),
                            )}
                        </ul>
                    </div>
                ) : null}

                <div className="mt-6 space-y-2">
                    <h2 className="text-sm font-medium">
                        {t('brain.proposals.diffHeading')}
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {t('brain.proposals.diffDescription')}
                    </p>
                    {diff ? (
                        <ProposalDiffView diff={diff} />
                    ) : proposal.body ? (
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
                    <Field className="mt-6 max-w-2xl">
                        <FieldLabel htmlFor="decision_note">
                            {t('brain.proposals.fieldDecisionNote')}
                        </FieldLabel>
                        <Textarea
                            id="decision_note"
                            value={decisionNote}
                            onChange={(event) =>
                                setDecisionNote(event.target.value)
                            }
                            rows={3}
                            maxLength={2000}
                            placeholder={t(
                                'brain.proposals.decisionNotePlaceholder',
                            )}
                        />
                    </Field>
                ) : null}

                {canDecide ? (
                    <div className="mt-4 flex flex-wrap gap-2">
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
        </AppLayout>
    );
}
