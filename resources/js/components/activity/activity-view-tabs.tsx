import { Link } from '@inertiajs/react';
import { Bot, ScrollText } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { agentCalls, index as activityIndex } from '@/routes/activity';

export type ActivityView = 'audit-log' | 'agent-calls';

type ActivityViewTabsProps = {
    view: ActivityView;
};

/**
 * Line tabs under the Activity header: Audit log and Agent calls each keep their URL
 * (app-ui docs/page.md "Views: tabs under the header").
 */
export function ActivityViewTabs({ view }: ActivityViewTabsProps) {
    const { t } = useTranslation();

    return (
        <Tabs
            value={view}
            activationMode="manual"
            className="overflow-x-auto border-b px-4"
        >
            <TabsList variant="line" aria-label={t('activity.tabs.ariaLabel')}>
                <TabsTrigger
                    asChild
                    aria-controls={undefined}
                    value="audit-log"
                >
                    <Link href={activityIndex.url()} prefetch>
                        <ScrollText />
                        {t('activity.tabs.auditLog')}
                    </Link>
                </TabsTrigger>
                <TabsTrigger
                    asChild
                    aria-controls={undefined}
                    value="agent-calls"
                >
                    <Link href={agentCalls.url()} prefetch>
                        <Bot />
                        {t('activity.tabs.agentCalls')}
                    </Link>
                </TabsTrigger>
            </TabsList>
        </Tabs>
    );
}
