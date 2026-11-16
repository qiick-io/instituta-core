import { Head } from '@inertiajs/react';
import { Brain } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { DashboardOverviewPanel } from '@/components/dashboard/dashboard-overview-panel';
import type { DashboardOverviewMetrics } from '@/components/dashboard/dashboard-types';
import { PageLayout } from '@/components/layout/page-layout';
import { dashboard } from '@/routes';
import AppLayout from '@/layouts/app-layout';

type DashboardProps =
    | ({ hasPerimeter: true } & DashboardOverviewMetrics)
    | { hasPerimeter: false };

/**
 * The first screen after sign-in: key numbers and short lists for the session perimeter.
 * Page structure copied from externa-core `pages/dashboard.tsx` (one view, so no tabs);
 * without a perimeter it shows the empty state instead of zeros.
 */
export default function Dashboard(props: DashboardProps) {
    const { t } = useTranslation();

    const layoutProps = {
        breadcrumbs: [{ title: t('dashboard.title'), href: dashboard() }],
    };

    return (
        <AppLayout {...layoutProps}>
            <Head title={t('dashboard.title')} />
            <PageLayout scrollContent>
                {props.hasPerimeter ? (
                    <DashboardOverviewPanel
                        contentStats={props.contentStats}
                        areaCounts={props.areaCounts}
                        sources={props.sources}
                        proposals={props.proposals}
                        apiKeys={props.apiKeys}
                        latestActivity={props.latestActivity}
                    />
                ) : (
                    <PageEmptyState
                        icon={Brain}
                        title={t('dashboard.noPerimeterTitle')}
                        description={t('dashboard.noPerimeterDescription')}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
