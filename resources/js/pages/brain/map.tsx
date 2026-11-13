import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Map as MapIcon, SearchX } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import { map, tree } from '@/routes/brain';
import type { BreadcrumbItem } from '@/types';

type AreaCard = {
    area: string;
    count: number;
    empty: boolean;
};

type Props = {
    areas: AreaCard[];
    hasPerimeter: boolean;
};

/**
 * Brain map: area coverage for the current scope (kickoff §5).
 * StatCard grid from `@qiick/stat-card` / dashboard pattern — counts and gaps, not bodies.
 */
export default function BrainMap({ areas, hasPerimeter }: Props) {
    const { t } = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('brain.map.title'),
                href: map.url(),
            },
        ],
        [t],
    );

    setLayoutProps({ breadcrumbs });

    return (
        <>
            <Head title={t('brain.map.title')} />
            <PageLayout description={t('brain.map.description')}>
                {!hasPerimeter ? (
                    <PageEmptyState
                        icon={SearchX}
                        title={
                            <EmptyStatus>{t('brain.scope.none')}</EmptyStatus>
                        }
                        description={t('brain.map.description')}
                        compact
                    />
                ) : areas.every((area) => area.empty) ? (
                    <PageEmptyState
                        icon={MapIcon}
                        title={t('brain.map.title')}
                        description={t('brain.map.emptyHint')}
                        compact
                    />
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {areas.map((area) => (
                            <Link
                                key={area.area}
                                href={tree.url({
                                    query: { area: area.area },
                                })}
                                className="rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <StatCard
                                    label={t(`brain.areas.${area.area}`, {
                                        defaultValue: area.area,
                                    })}
                                    value={String(area.count)}
                                    headline={
                                        area.empty ? (
                                            <Badge variant="outline">
                                                {t('brain.map.gap')}
                                            </Badge>
                                        ) : (
                                            t('brain.map.openTree')
                                        )
                                    }
                                    context={
                                        area.empty
                                            ? t('brain.map.emptyHint')
                                            : t('brain.map.count', {
                                                  count: area.count,
                                              })
                                    }
                                    className={
                                        area.empty
                                            ? 'border-dashed opacity-90'
                                            : undefined
                                    }
                                />
                            </Link>
                        ))}
                    </div>
                )}
            </PageLayout>
        </>
    );
}
