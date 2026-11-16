import { Link, usePage } from '@inertiajs/react';
import { CircleHelp, FilePenLine } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { index as gapsIndex } from '@/routes/review/gaps';
import { index as proposalsIndex } from '@/routes/review/proposals';

/**
 * The views of Review (#255) as line tabs under the header (app-ui `docs/page.md`, "Views: tabs
 * under the header"): proposed changes and reported gaps, each with its own URL and its open
 * count. The page has no TabsContent, so each trigger drops Radix's `aria-controls`.
 */
export function ReviewTabs({ view }: { view: 'proposals' | 'gaps' }) {
    const { t } = useTranslation();
    const { review } = usePage().props;

    const views = [
        {
            value: 'proposals',
            href: proposalsIndex.url(),
            icon: FilePenLine,
            label: t('review.tabs.proposals'),
            count: review.open_proposals,
        },
        {
            value: 'gaps',
            href: gapsIndex.url(),
            icon: CircleHelp,
            label: t('review.tabs.gaps'),
            count: review.open_gaps,
        },
    ] as const;

    return (
        <Tabs
            value={view}
            activationMode="manual"
            className="overflow-x-auto border-b px-4"
        >
            <TabsList variant="line" aria-label={t('review.tabs.label')}>
                {views.map((tab) => (
                    <TabsTrigger
                        key={tab.value}
                        value={tab.value}
                        asChild
                        aria-controls={undefined}
                    >
                        <Link href={tab.href}>
                            <tab.icon />
                            {tab.label}
                            {tab.count > 0 ? (
                                <Badge
                                    variant="secondary"
                                    className="min-w-5 rounded-full px-1.5 tabular-nums"
                                >
                                    {tab.count}
                                </Badge>
                            ) : null}
                        </Link>
                    </TabsTrigger>
                ))}
            </TabsList>
        </Tabs>
    );
}
