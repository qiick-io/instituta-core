import { Head, Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { BrainGraphCanvas } from '@/components/brain/brain-graph-canvas';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import { graph, tree } from '@/routes/brain';

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

    const setDepth = (nextDepth: number) => {
        router.get(
            graph.url(itemId ?? undefined),
            { depth: nextDepth },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={t('brain.graph.title')} />
            <PageLayout description={t('brain.graph.description')}>
                <div className="flex flex-wrap items-end justify-between gap-3 pb-2">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('brain.graph.title')}
                        </h1>
                        {root ? (
                            <p className="text-sm text-muted-foreground">
                                {t('brain.graph.rootLabel', {
                                    title: root.title,
                                })}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant={depth === 1 ? 'default' : 'outline'}
                            onClick={() => setDepth(1)}
                        >
                            {t('brain.graph.depth1')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={depth === 2 ? 'default' : 'outline'}
                            onClick={() => setDepth(2)}
                        >
                            {t('brain.graph.depth2')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            asChild
                        >
                            <Link href={tree.url()}>
                                {t('brain.graph.openTree')}
                            </Link>
                        </Button>
                    </div>
                </div>

                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : root === null ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.graph.empty')}
                    </p>
                ) : (
                    <BrainGraphCanvas
                        rootId={root.id}
                        nodes={nodes}
                        edges={edges}
                    />
                )}
            </PageLayout>
        </>
    );
}

BrainGraph.layout = {
    breadcrumbs: [
        {
            title: 'Graph',
            href: graph.url(),
        },
    ],
};
