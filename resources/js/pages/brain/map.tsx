import { Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { map, tree } from '@/routes/brain';
import AppLayout from '@/layouts/app-layout';

type AreaCard = {
    area: string;
    count: number;
    empty: boolean;
};

type Props = {
    areas: AreaCard[];
    hasPerimeter: boolean;
};

export default function BrainMap({ areas, hasPerimeter }: Props) {
    const { t } = useTranslation();
    const layoutProps = {
        breadcrumbs: [
            {
                title: t('brain.nav.map'),
                href: map.url(),
            },
        ],
    };

    return (
        <AppLayout {...layoutProps}>
            <Head title={t('brain.map.title')} />
            <PageLayout description={t('brain.map.description')} scrollContent>
                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {areas.map((area) => (
                            <Link
                                key={area.area}
                                href={tree.url({ query: { area: area.area } })}
                                className={cn(
                                    'rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:bg-muted/40 dark:border-sidebar-border',
                                    area.empty && 'border-dashed bg-muted/30',
                                )}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <h2 className="font-medium">
                                        {t(`brain.areas.${area.area}`, {
                                            defaultValue: area.area,
                                        })}
                                    </h2>
                                    {area.empty ? (
                                        <Badge variant="outline">
                                            {t('brain.map.gap')}
                                        </Badge>
                                    ) : (
                                        <Badge variant="secondary">
                                            {t('brain.map.count', {
                                                count: area.count,
                                                defaultValue: `${area.count}`,
                                            })}
                                        </Badge>
                                    )}
                                </div>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {area.empty
                                        ? t('brain.map.emptyHint')
                                        : t('brain.map.openTree')}
                                </p>
                            </Link>
                        ))}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
