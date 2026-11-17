import { Head, router } from '@inertiajs/react';
import { Network, RotateCcw } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import {
    BrainGraphCanvas,
    type CentreRequest,
} from '@/components/brain/brain-graph-canvas';
import { GraphItemCard } from '@/components/brain/graph-item-card';
import { FilterSearch } from '@/components/layout/page-header';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import {
    type BrainGraphEdgeInput,
    type BrainGraphNodeInput,
    type PlacedBrainNode,
    layoutBrainGraph,
} from '@/lib/brain-force-layout';
import { AREA_TOKENS, areaIndex } from '@/lib/brain-graph-render';
import { cn } from '@/lib/utils';
import { graph } from '@/routes';
import { show as explorerShow } from '@/routes/explorer';

type Props = {
    hasPerimeter: boolean;
    nodes: BrainGraphNodeInput[];
    edges: BrainGraphEdgeInput[];
    /** Stable key of the item to select first (`?focus=`). */
    focus: string | null;
};

/** The legend dot uses the same softened area colour as the canvas. */
function legendColour(area: string, areas: string[]): string {
    return `color-mix(in oklab, var(${AREA_TOKENS[areaIndex(area, areas)]}) 78%, var(--card))`;
}

/**
 * The brain as a graph (#254, #270, #273): every item in the perimeter and the links between them.
 * Clicking a node selects it and the side card previews its content, lists its linked items and
 * opens it; double click opens it straight away. The legend switches areas on and off; search
 * highlights every match and centres the best one (selecting it when it is the only one).
 */
export default function BrainGraph({
    hasPerimeter,
    nodes,
    edges,
    focus,
}: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [matchIds, setMatchIds] = useState<string[] | null>(null);
    const [hiddenAreas, setHiddenAreas] = useState<string[]>([]);
    const [fitRequest, setFitRequest] = useState(0);
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
    const countsByArea = useMemo(() => {
        const counts = new Map<string, number>();

        for (const node of nodes) {
            counts.set(node.area, (counts.get(node.area) ?? 0) + 1);
        }

        return counts;
    }, [nodes]);
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
            .filter(
                (node) => ids.has(node.id) && !hiddenAreas.includes(node.area),
            )
            .sort((a, b) => b.degree - a.degree);
    }, [edges, placedNodes, selectedId, hiddenAreas]);

    const areaLabel = (area: string): string =>
        t(`brain.areas.${area}`, { defaultValue: area });

    const selectAndCentre = (id: string): void => {
        setSelectedId(id);
        setCentreRequest({ id, nonce: Date.now() });
    };

    const open = useCallback((node: PlacedBrainNode): void => {
        router.visit(explorerShow.url(node.stable_key));
    }, []);

    const findMatches = (value: string, hidden: string[]): void => {
        const needle = value.trim().toLowerCase();

        if (needle === '') {
            setMatchIds(null);

            return;
        }

        const matches = nodes
            .filter(
                (node) =>
                    !hidden.includes(node.area) &&
                    (node.title.toLowerCase().includes(needle) ||
                        node.stable_key.toLowerCase().includes(needle)),
            )
            .sort((a, b) => b.degree - a.degree);

        setMatchIds(matches.map((node) => node.id));

        if (matches.length === 1) {
            selectAndCentre(matches[0].id);
        } else if (matches.length > 1) {
            setSelectedId(null);
            setCentreRequest({ id: matches[0].id, nonce: Date.now() });
        }
    };

    const searchFor = (value: string): void => {
        setSearch(value);
        findMatches(value, hiddenAreas);
    };

    const updateHiddenAreas = (hidden: string[]): void => {
        setHiddenAreas(hidden);
        setFitRequest((request) => request + 1);

        if (selected !== null && hidden.includes(selected.area)) {
            setSelectedId(null);
        }

        if (search.trim() !== '') {
            findMatches(search, hidden);
        }
    };

    const toggleArea = (area: string): void =>
        updateHiddenAreas(
            hiddenAreas.includes(area)
                ? hiddenAreas.filter((hidden) => hidden !== area)
                : [...hiddenAreas, area],
        );

    const hasGraph = hasPerimeter && nodes.length > 0;

    return (
        <AppLayout
            breadcrumbs={[{ title: t('brain.nav.graph'), href: graph.url() }]}
        >
            <Head title={t('brain.nav.graph')} />
            <PageLayout
                className="min-h-0"
                description={t('brain.graph.description')}
                filters={
                    hasGraph ? (
                        <FilterSearch
                            value={search}
                            onChange={searchFor}
                            placeholder={t('brain.graph.searchPlaceholder')}
                        />
                    ) : undefined
                }
                filtersRight={
                    hasGraph ? (
                        <div className="flex flex-wrap items-center gap-1">
                            <ul
                                className="flex flex-wrap items-center gap-1"
                                aria-label={t('brain.graph.legendLabel')}
                            >
                                {areas.map((area) => {
                                    const isHidden = hiddenAreas.includes(area);

                                    return (
                                        <li key={area}>
                                            <button
                                                type="button"
                                                aria-pressed={!isHidden}
                                                title={t(
                                                    isHidden
                                                        ? 'brain.graph.showArea'
                                                        : 'brain.graph.hideArea',
                                                    { area: areaLabel(area) },
                                                )}
                                                onClick={() => toggleArea(area)}
                                                className={cn(
                                                    'flex items-center gap-1.5 rounded-full border border-transparent px-2.5 py-1 text-sm text-muted-foreground transition-colors hover:border-border hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                                    isHidden &&
                                                        'line-through opacity-50',
                                                )}
                                            >
                                                <span
                                                    aria-hidden="true"
                                                    className={cn(
                                                        'size-2.5 rounded-full transition-transform',
                                                        isHidden && 'scale-75',
                                                    )}
                                                    style={{
                                                        backgroundColor:
                                                            isHidden
                                                                ? 'var(--muted-foreground)'
                                                                : legendColour(
                                                                      area,
                                                                      areas,
                                                                  ),
                                                    }}
                                                />
                                                {areaLabel(area)}
                                                <span className="text-xs tabular-nums opacity-70">
                                                    {countsByArea.get(area) ??
                                                        0}
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                            {hiddenAreas.length > 0 ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => updateHiddenAreas([])}
                                >
                                    <RotateCcw />
                                    {t('brain.graph.showAll')}
                                </Button>
                            ) : null}
                        </div>
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
                            hiddenAreas={hiddenAreas}
                            matchIds={matchIds}
                            selectedId={selectedId}
                            onSelect={setSelectedId}
                            onOpen={open}
                            centreRequest={centreRequest}
                            fitRequest={fitRequest}
                        />
                        {matchIds !== null && selected === null ? (
                            <p
                                className="absolute top-3 left-3 rounded-full border bg-card/90 px-3 py-1 text-xs text-muted-foreground shadow-sm backdrop-blur"
                                aria-live="polite"
                            >
                                {t('brain.graph.matches', {
                                    count: matchIds.length,
                                })}
                            </p>
                        ) : null}
                        {selected ? (
                            <GraphItemCard
                                item={selected}
                                neighbours={neighbours}
                                areaLabel={areaLabel}
                                areaColour={(area) => legendColour(area, areas)}
                                onSelectNeighbour={selectAndCentre}
                                onClose={() => setSelectedId(null)}
                            />
                        ) : null}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
