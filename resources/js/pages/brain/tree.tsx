import { Head, Link } from '@inertiajs/react';
import { ListTree, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    ProposeChangeDrawer,
    type ProposeChangeTarget,
} from '@/components/brain/propose-change-drawer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { markdownToPlainText } from '@/lib/markdown-plain-text';
import { cn } from '@/lib/utils';
import { reading, tree } from '@/routes/brain';
import AppLayout from '@/layouts/app-layout';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';

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
    /** False for guests: they read but never propose. */
    canPropose: boolean;
};

export default function BrainTree({
    groups,
    area,
    total,
    hasPerimeter,
    canPropose,
}: Props) {
    const { t } = useTranslation();
    const layoutProps = {
        breadcrumbs: [
            {
                title: t('brain.nav.tree'),
                href: tree.url(),
            },
        ],
    };
    const [isCreating, setIsCreating] = useState(false);
    // Stable identity: the drawer resets its form when the target changes.
    const createTarget = useMemo<ProposeChangeTarget>(
        () => ({ mode: 'create', area }),
        [area],
    );

    return (
        <AppLayout
            {...layoutProps}
            headerActions={
                canPropose ? (
                    <Button type="button" onClick={() => setIsCreating(true)}>
                        <Plus />
                        {t('brain.propose.newItem')}
                    </Button>
                ) : undefined
            }
        >
            <Head title={t('brain.tree.title')} />
            <PageLayout
                description={t('brain.tree.description')}
                subheader={
                    hasPerimeter ? (
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
                    ) : undefined
                }
                scrollContent
            >
                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : (
                    <>
                        {groups.length === 0 ? (
                            <PageEmptyState
                                icon={ListTree}
                                title={
                                    <EmptyStatus>
                                        {t('brain.tree.empty')}
                                    </EmptyStatus>
                                }
                                description={t('brain.tree.emptyDescription')}
                            />
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
                                                                {markdownToPlainText(
                                                                    item.summary,
                                                                )}
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
            </PageLayout>

            <Drawer
                direction="right"
                open={isCreating}
                onOpenChange={setIsCreating}
            >
                <ProposeChangeDrawer
                    target={isCreating ? createTarget : null}
                    open={isCreating}
                    onCancel={() => setIsCreating(false)}
                />
            </Drawer>
        </AppLayout>
    );
}
