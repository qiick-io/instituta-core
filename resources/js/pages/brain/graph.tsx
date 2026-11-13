import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { GitFork, SearchX } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { BrainGraphCanvas } from '@/components/brain/brain-graph-canvas';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { graph, tree } from '@/routes/brain';
import type { BreadcrumbItem } from '@/types';

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

/**
 * Scoped neighbour graph — not the home screen (kickoff §5).
 * Card + ToggleGroup chrome from app-ui page patterns; canvas unchanged.
 */
export default function BrainGraph({
    hasPerimeter,
    root,
    nodes,
    edges,
    depth,
    itemId,
}: Props) {
    const { t } = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('brain.graph.title'),
                href: graph.url(),
            },
        ],
        [t],
    );

    setLayoutProps({ breadcrumbs });

    const setDepth = (nextDepth: string) => {
        const parsed = Number(nextDepth);

        if (parsed !== 1 && parsed !== 2) {
            return;
        }

        router.get(
            graph.url(itemId ?? undefined),
            { depth: parsed },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={t('brain.graph.title')} />
            <PageLayout
                description={t('brain.graph.description')}
                filters={
                    hasPerimeter ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={String(depth)}
                                onValueChange={(value) => {
                                    if (value) {
                                        setDepth(value);
                                    }
                                }}
                            >
                                <ToggleGroupItem value="1">
                                    {t('brain.graph.depth1')}
                                </ToggleGroupItem>
                                <ToggleGroupItem value="2">
                                    {t('brain.graph.depth2')}
                                </ToggleGroupItem>
                            </ToggleGroup>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={tree.url()}>
                                    {t('brain.graph.openTree')}
                                </Link>
                            </Button>
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
                        description={t('brain.graph.description')}
                        compact
                    />
                ) : root === null ? (
                    <PageEmptyState
                        icon={GitFork}
                        title={t('brain.graph.title')}
                        description={t('brain.graph.empty')}
                        action={
                            <Button variant="outline" asChild>
                                <Link href={tree.url()}>
                                    {t('brain.graph.openTree')}
                                </Link>
                            </Button>
                        }
                        compact
                    />
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>{root.title}</CardTitle>
                            <CardDescription>
                                {t('brain.graph.rootLabel', {
                                    title: root.title,
                                })}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <BrainGraphCanvas
                                rootId={root.id}
                                nodes={nodes}
                                edges={edges}
                            />
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </>
    );
}
