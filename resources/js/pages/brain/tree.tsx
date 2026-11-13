import { Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { reading, tree } from '@/routes/brain';

type TreeItem = {
    id: string;
    title: string;
    path: string;
    stable_key: string;
    type: string;
    summary: string | null;
};

type Group = {
    area: string;
    items: TreeItem[];
};

type Props = {
    groups: Group[];
    area: string | null;
    total: number;
    hasPerimeter: boolean;
};

export default function BrainTree({
    groups,
    area,
    total,
    hasPerimeter,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('brain.tree.title')} />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
                <div className="space-y-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('brain.tree.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('brain.tree.description')}
                    </p>
                </div>

                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant="secondary">
                                {t('brain.tree.count', {
                                    count: total,
                                    defaultValue: `${total}`,
                                })}
                            </Badge>
                            {area ? (
                                <>
                                    <Badge variant="outline">
                                        {t(`brain.areas.${area}`, {
                                            defaultValue: area,
                                        })}
                                    </Badge>
                                    <Link
                                        href={tree.url()}
                                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                                    >
                                        {t('brain.tree.clearArea')}
                                    </Link>
                                </>
                            ) : null}
                        </div>

                        {groups.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('brain.tree.empty')}
                            </p>
                        ) : (
                            <div className="space-y-6">
                                {groups.map((group) => (
                                    <section
                                        key={group.area}
                                        className="space-y-2"
                                    >
                                        <h2 className="text-sm font-medium tracking-wide text-muted-foreground uppercase">
                                            {t(`brain.areas.${group.area}`, {
                                                defaultValue: group.area,
                                            })}
                                        </h2>
                                        <ul className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                                            {group.items.map((item) => (
                                                <li key={item.id}>
                                                    <Link
                                                        href={reading.url(
                                                            item.id,
                                                        )}
                                                        className={cn(
                                                            'flex flex-col gap-1 px-4 py-3 transition-colors hover:bg-muted/40',
                                                        )}
                                                    >
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-medium">
                                                                {item.title}
                                                            </span>
                                                            <Badge variant="outline">
                                                                {item.type}
                                                            </Badge>
                                                        </div>
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            {item.path}
                                                        </span>
                                                        {item.summary ? (
                                                            <span className="text-sm text-muted-foreground">
                                                                {item.summary}
                                                            </span>
                                                        ) : null}
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    </section>
                                ))}
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

BrainTree.layout = {
    breadcrumbs: [
        {
            title: 'Tree',
            href: tree.url(),
        },
    ],
};
