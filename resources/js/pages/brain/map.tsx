import { Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

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

    return (
        <>
            <Head title={t('brain.map.title')} />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
                <div className="space-y-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('brain.map.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('brain.map.description')}
                    </p>
                </div>

                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {areas.map((area) => (
                            <Link
                                key={area.area}
                                href={`/brain/tree?area=${encodeURIComponent(area.area)}`}
                                className={cn(
                                    'rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:bg-muted/40 dark:border-sidebar-border',
                                    area.empty && 'border-dashed opacity-70',
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
            </div>
        </>
    );
}

BrainMap.layout = {
    breadcrumbs: [
        {
            title: 'Map',
            href: '/brain/map',
        },
    ],
};
