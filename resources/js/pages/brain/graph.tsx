import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, Network, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import {
    BrainGraphCanvas,
    areaColour,
} from '@/components/brain/brain-graph-canvas';
import { FilterSearch } from '@/components/layout/page-header';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import {
    type BrainGraphEdgeInput,
    type BrainGraphNodeInput,
    layoutBrainGraph,
} from '@/lib/brain-force-layout';
import { graph } from '@/routes';
import { show as explorerShow } from '@/routes/explorer';

type Props = {
    hasPerimeter: boolean;
    nodes: BrainGraphNodeInput[];
    edges: BrainGraphEdgeInput[];
    /** Stable key of the item to select first (`?focus=`). */
    focus: string | null;
};

/**
 * The brain as a graph (#254): every item in the perimeter and the links between them, laid out
 * by a force simulation. Search selects an item; selecting lights its neighbours; the card opens
 * it in the explorer. A legend names the area colours.
 */
export default function BrainGraph({
    hasPerimeter,
    nodes,
    edges,
    focus,
}: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [selectedId, setSelectedId] = useState<string | null>(
        () => nodes.find((node) => node.stable_key === focus)?.id ?? null,
    );

    const placedNodes = useMemo(
        () => layoutBrainGraph(nodes, edges),
        [nodes, edges],
    );
    const areas = useMemo(
        () => [...new Set(nodes.map((node) => node.area))].sort(),
        [nodes],
    );
    const selected = placedNodes.find((node) => node.id === selectedId) ?? null;

    const searchFor = (value: string): void => {
        setSearch(value);
        const needle = value.trim().toLowerCase();

        if (needle === '') {
            return;
        }

        const match = nodes.find(
            (node) =>
                node.title.toLowerCase().includes(needle) ||
                node.stable_key.toLowerCase().includes(needle),
        );

        if (match) {
            setSelectedId(match.id);
        }
    };

    return (
        <AppLayout
            breadcrumbs={[{ title: t('brain.nav.graph'), href: graph.url() }]}
        >
            <Head title={t('brain.nav.graph')} />
            <PageLayout
                className="min-h-0"
                description={t('brain.graph.description')}
                filters={
                    hasPerimeter && nodes.length > 0 ? (
                        <FilterSearch
                            value={search}
                            onChange={searchFor}
                            placeholder={t('brain.graph.searchPlaceholder')}
                        />
                    ) : undefined
                }
                filtersRight={
                    hasPerimeter && nodes.length > 0 ? (
                        <ul
                            className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground"
                            aria-label={t('brain.graph.legendLabel')}
                        >
                            {areas.map((area) => (
                                <li
                                    key={area}
                                    className="flex items-center gap-1.5"
                                >
                                    <span
                                        aria-hidden="true"
                                        className="size-2.5 rounded-full"
                                        style={{
                                            backgroundColor: areaColour(
                                                area,
                                                areas,
                                            ),
                                        }}
                                    />
                                    {t(`brain.areas.${area}`, {
                                        defaultValue: area,
                                    })}
                                </li>
                            ))}
                        </ul>
                    ) : undefined
                }
            >
                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : nodes.length === 0 ? (
                    <PageEmptyState
                        icon={Network}
                        title={t('brain.graph.empty')}
                        description={t('brain.graph.emptyDescription')}
                    />
                ) : (
                    <div className="relative min-h-0 flex-1">
                        <BrainGraphCanvas
                            nodes={placedNodes}
                            edges={edges}
                            areas={areas}
                            selectedId={selectedId}
                            onSelect={setSelectedId}
                        />
                        {selected ? (
                            <Card className="absolute top-3 left-3 w-72 gap-3 py-4 shadow-md">
                                <CardHeader className="px-4">
                                    <CardTitle className="truncate text-base">
                                        {selected.title}
                                    </CardTitle>
                                    <CardDescription className="font-mono text-xs">
                                        {selected.stable_key}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3 px-4">
                                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                        <Badge variant="outline">
                                            {t(`brain.areas.${selected.area}`, {
                                                defaultValue: selected.area,
                                            })}
                                        </Badge>
                                        <span>
                                            {t('brain.graph.links', {
                                                count: selected.degree,
                                            })}
                                        </span>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button asChild size="sm">
                                            <Link
                                                href={explorerShow.url(
                                                    selected.stable_key,
                                                )}
                                            >
                                                <ArrowUpRight />
                                                {t('brain.graph.open')}
                                            </Link>
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setSelectedId(null)}
                                        >
                                            <X />
                                            {t('brain.graph.clearSelection')}
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
