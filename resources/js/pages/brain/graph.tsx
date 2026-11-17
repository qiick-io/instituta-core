import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, Network, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import {
    BrainGraphCanvas,
    type CentreRequest,
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
    type PlacedBrainNode,
    layoutBrainGraph,
} from '@/lib/brain-force-layout';
import { AREA_TOKENS, areaIndex } from '@/lib/brain-graph-render';
import { graph } from '@/routes';
import { show as explorerShow } from '@/routes/explorer';

type Props = {
    hasPerimeter: boolean;
    nodes: BrainGraphNodeInput[];
    edges: BrainGraphEdgeInput[];
    /** Stable key of the item to select first (`?focus=`). */
    focus: string | null;
};

/** How many neighbours the side card lists before it says "and N more". */
const LISTED_NEIGHBOURS = 8;

/** The legend dot uses the same softened area colour as the canvas. */
function legendColour(area: string, areas: string[]): string {
    return `color-mix(in oklab, var(${AREA_TOKENS[areaIndex(area, areas)]}) 78%, var(--card))`;
}

/**
 * The brain as a graph (#254, #270): every item in the perimeter and the links between them.
 * Clicking a node selects it and the side card shows it with its linked items (each selectable)
 * and Open; double click opens it straight away. Search selects and centres the first match.
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
    const [centreRequest, setCentreRequest] = useState<CentreRequest>(null);

    const placedNodes = useMemo(
        () => layoutBrainGraph(nodes, edges),
        [nodes, edges],
    );
    const areas = useMemo(
        () => [...new Set(nodes.map((node) => node.area))].sort(),
        [nodes],
    );
    const selected = placedNodes.find((node) => node.id === selectedId) ?? null;

    const neighbours = useMemo(() => {
        if (selectedId === null) {
            return [];
        }

        const ids = new Set<string>();

        for (const edge of edges) {
            if (edge.from === selectedId) {
                ids.add(edge.to);
            } else if (edge.to === selectedId) {
                ids.add(edge.from);
            }
        }

        return placedNodes
            .filter((node) => ids.has(node.id))
            .sort((a, b) => b.degree - a.degree);
    }, [edges, placedNodes, selectedId]);

    const selectAndCentre = (id: string): void => {
        setSelectedId(id);
        setCentreRequest({ id, nonce: Date.now() });
    };

    const open = useCallback((node: PlacedBrainNode): void => {
        router.visit(explorerShow.url(node.stable_key));
    }, []);

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
            selectAndCentre(match.id);
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
                                            backgroundColor: legendColour(
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
                            onOpen={open}
                            centreRequest={centreRequest}
                        />
                        {selected ? (
                            <Card className="absolute top-3 left-3 max-h-[calc(100%-1.5rem)] w-72 gap-3 overflow-y-auto bg-card/90 py-4 shadow-md backdrop-blur">
                                <CardHeader className="px-4">
                                    <CardTitle className="text-base leading-snug break-words">
                                        {selected.title}
                                    </CardTitle>
                                    <CardDescription className="font-mono text-xs break-all">
                                        {selected.stable_key}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3 px-4">
                                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                        <Badge variant="outline">
                                            <span
                                                aria-hidden="true"
                                                className="size-2 rounded-full"
                                                style={{
                                                    backgroundColor:
                                                        legendColour(
                                                            selected.area,
                                                            areas,
                                                        ),
                                                }}
                                            />
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
                                    {neighbours.length > 0 ? (
                                        <div className="flex flex-col gap-1">
                                            <p className="text-xs font-medium text-muted-foreground">
                                                {t('brain.graph.neighbours')}
                                            </p>
                                            <ul className="-mx-2 flex flex-col">
                                                {neighbours
                                                    .slice(0, LISTED_NEIGHBOURS)
                                                    .map((neighbour) => (
                                                        <li key={neighbour.id}>
                                                            <button
                                                                type="button"
                                                                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                                onClick={() =>
                                                                    selectAndCentre(
                                                                        neighbour.id,
                                                                    )
                                                                }
                                                            >
                                                                <span
                                                                    aria-hidden="true"
                                                                    className="size-2 shrink-0 rounded-full"
                                                                    style={{
                                                                        backgroundColor:
                                                                            legendColour(
                                                                                neighbour.area,
                                                                                areas,
                                                                            ),
                                                                    }}
                                                                />
                                                                <span className="truncate">
                                                                    {
                                                                        neighbour.title
                                                                    }
                                                                </span>
                                                            </button>
                                                        </li>
                                                    ))}
                                            </ul>
                                            {neighbours.length >
                                            LISTED_NEIGHBOURS ? (
                                                <p className="text-xs text-muted-foreground">
                                                    {t(
                                                        'brain.graph.moreNeighbours',
                                                        {
                                                            count:
                                                                neighbours.length -
                                                                LISTED_NEIGHBOURS,
                                                        },
                                                    )}
                                                </p>
                                            ) : null}
                                        </div>
                                    ) : null}
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
