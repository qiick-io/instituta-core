import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { FolderTree, SearchX } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Item,
    ItemContent,
    ItemDescription,
    ItemGroup,
    ItemTitle,
} from '@/components/ui/item';
import { ScrollArea } from '@/components/ui/scroll-area';
import { reading, tree } from '@/routes/brain';
import type { BreadcrumbItem } from '@/types';

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

/**
 * Brain tree by meaning (area → items), not raw git paths (kickoff §5).
 * Item rows from `@qiick/item`; collapsible area sections from `@qiick/collapsible`.
 */
export default function BrainTree({
    groups,
    area,
    total,
    hasPerimeter,
}: Props) {
    const { t } = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('brain.tree.title'),
                href: tree.url(),
            },
        ],
        [t],
    );

    setLayoutProps({ breadcrumbs });

    return (
        <>
            <Head title={t('brain.tree.title')} />
            <PageLayout
                description={t('brain.tree.description')}
                filters={
                    hasPerimeter ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant="secondary">
                                {t('brain.tree.count', { count: total })}
                            </Badge>
                            {area ? (
                                <>
                                    <Badge variant="outline">
                                        {t(`brain.areas.${area}`, {
                                            defaultValue: area,
                                        })}
                                    </Badge>
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link href={tree.url()}>
                                            {t('brain.tree.clearArea')}
                                        </Link>
                                    </Button>
                                </>
                            ) : null}
                        </div>
                    ) : null
                }
            >
                {!hasPerimeter ? (
                    <PageEmptyState
                        icon={SearchX}
                        title={
                            <EmptyStatus>{t('brain.scope.none')}</EmptyStatus>
                        }
                        description={t('brain.tree.description')}
                        compact
                    />
                ) : groups.length === 0 ? (
                    <PageEmptyState
                        icon={FolderTree}
                        title={t('brain.tree.title')}
                        description={t('brain.tree.empty')}
                        compact
                    />
                ) : (
                    <ScrollArea className="h-[min(70vh,40rem)] pr-2">
                        <div className="space-y-4">
                            {groups.map((group) => (
                                <Collapsible
                                    key={group.area}
                                    defaultOpen
                                    className="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
                                >
                                    <CollapsibleTrigger className="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-medium tracking-wide text-muted-foreground uppercase hover:bg-muted/40">
                                        <span>
                                            {t(`brain.areas.${group.area}`, {
                                                defaultValue: group.area,
                                            })}
                                        </span>
                                        <Badge variant="secondary">
                                            {group.items.length}
                                        </Badge>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <ItemGroup className="gap-1 border-t border-sidebar-border/70 p-2 dark:border-sidebar-border">
                                            {group.items.map((item) => (
                                                <Item
                                                    key={item.id}
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={reading.url(
                                                            item.id,
                                                        )}
                                                    >
                                                        <ItemContent>
                                                            <ItemTitle>
                                                                {item.title}
                                                                <Badge variant="outline">
                                                                    {item.type}
                                                                </Badge>
                                                            </ItemTitle>
                                                            <ItemDescription>
                                                                {item.summary ??
                                                                    item.path}
                                                            </ItemDescription>
                                                        </ItemContent>
                                                    </Link>
                                                </Item>
                                            ))}
                                        </ItemGroup>
                                    </CollapsibleContent>
                                </Collapsible>
                            ))}
                        </div>
                    </ScrollArea>
                )}
            </PageLayout>
        </>
    );
}
