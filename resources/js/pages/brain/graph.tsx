import { Head, Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { BrainGraphCanvas } from '@/components/brain/brain-graph-canvas';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import { graph, tree } from '@/routes/brain';
import AppLayout from '@/layouts/app-layout';
import { GitFork, ListTree } from 'lucide-react';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

type GraphNode = {
    id: string;
    title: string;
    area: string | null;
};

type GraphEdge = {
    from: string;
    to: string;
    relation: string | null;
};

type Props = {
    hasPerimeter: boolean;
    root: GraphNode | null;
    nodes: GraphNode[];
    edges: GraphEdge[];
    depth: number;
    itemId: string | null;
};

export default function BrainGraph({
    hasPerimeter,
    root,
    nodes,
    edges,
    depth,
    itemId,
}: Props) {
    const { t } = useTranslation();
    const layoutProps = {
        breadcrumbs: [
            { title: t('brain.nav.graph'), href: graph.url() },
            ...(root ? [{ title: root.title, href: graph.url(root.id) }] : []),
        ],
    };

    const setDepth = (nextDepth: number) => {
        router.get(
            graph.url(itemId ?? undefined),
            { depth: nextDepth },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AppLayout
            {...layoutProps}
            headerActions={
                <Button type="button" variant="outline" asChild>
                    <Link href={tree.url()}>
                        <ListTree />
                        {t('brain.graph.openTree')}
                    </Link>
                </Button>
            }
        >
            <Head title={t('brain.graph.title')} />
            <PageLayout
                description={
                    root
                        ? t('brain.graph.rootLabel', { title: root.title })
                        : t('brain.graph.description')
                }
                filtersRight={
                    root ? (
                        <ToggleGroup
                            type="single"
                            value={String(depth)}
                            onValueChange={(value) => {
                                if (value !== '') {
                                    setDepth(Number(value));
                                }
                            }}
                            variant="outline"
                            aria-label={t('brain.graph.depthLabel')}
                        >
                            <ToggleGroupItem value="1">
                                {t('brain.graph.depth1')}
                            </ToggleGroupItem>
                            <ToggleGroupItem value="2">
                                {t('brain.graph.depth2')}
                            </ToggleGroupItem>
                        </ToggleGroup>
                    ) : undefined
                }
            >
                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : root === null ? (
                    <PageEmptyState
                        icon={GitFork}
                        title={
                            <EmptyStatus>{t('brain.graph.empty')}</EmptyStatus>
                        }
                        description={t('brain.graph.description')}
                    />
                ) : (
                    <BrainGraphCanvas
                        rootId={root.id}
                        nodes={nodes}
                        edges={edges}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
