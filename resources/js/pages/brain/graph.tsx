import { Head, router } from '@inertiajs/react';
import { History, Network, Orbit, Palette } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { ComponentType } from 'react';
import { useTranslation } from 'react-i18next';
import {
    DataTableFacetedFilter,
    type DataTableFacetedFilterOption,
} from '@/components/admin/data-table-faceted-filter';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import {
    BrainGraphCanvas,
    type CentreRequest,
    type GraphColourBy,
} from '@/components/brain/brain-graph-canvas';
import { GraphItemCard } from '@/components/brain/graph-item-card';
import { GraphPathCard } from '@/components/brain/graph-path-card';
import { FilterSearch } from '@/components/layout/page-header';
import { PageLayout } from '@/components/layout/page-layout';
import { SegmentedIconItem } from '@/components/admin/segmented-icon-item';
import { ToggleGroup } from '@/components/ui/toggle-group';
import { Spinner } from '@/components/ui/spinner';
import { useBrainLayout } from '@/hooks/use-brain-layout';
import AppLayout from '@/layouts/app-layout';
import {
    type BrainGraphEdgeInput,
    type BrainGraphNodeInput,
    type PlacedBrainNode,
} from '@/lib/brain-force-layout';
import { shortestPath } from '@/lib/brain-graph-path';
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
    /** The rest of the view from the query string (#279). */
    view: {
        areas: string[];
        layout: 'free' | 'constellations';
        /** Stable key of the item to trace a path to from `focus` (`?to=`). */
        to: string | null;
        /** Review signals to show only (`?review=proposals,gaps`) (#287). */
        review: ReviewSignal[];
        colour: GraphColourBy;
    };
};

type ReviewSignal = 'proposals' | 'gaps';

/** The legend dot uses the same softened area colour as the canvas. */
function legendColour(area: string, areas: string[]): string {
    return `color-mix(in oklab, var(${AREA_TOKENS[areaIndex(area, areas)]}) 78%, var(--card))`;
}

/**
 * The brain as a graph (#254, #270, #273, #275): every item in the perimeter and the links between
 * them. Clicking a node selects it and the side card previews its content, lists its linked items
 * and opens it; double click opens it straight away; Shift-click a second node traces how the two
 * connect. The legend switches areas on and off, Constellations groups the nodes by area; search
 * highlights every match and centres the best one (selecting it when it is the only one).
 */
export default function BrainGraph({
    hasPerimeter,
    nodes,
    edges,
    focus,
    view,
}: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [matchIds, setMatchIds] = useState<string[] | null>(null);
    /** Areas picked in the Area filter; none picked shows every area. */
    const [shownAreas, setShownAreas] = useState<string[]>(() =>
        view.areas.filter((area) => nodes.some((node) => node.area === area)),
    );
    const [fitRequest, setFitRequest] = useState(0);
    const [selectedId, setSelectedId] = useState<string | null>(
        () => nodes.find((node) => node.stable_key === focus)?.id ?? null,
    );
    const [centreRequest, setCentreRequest] = useState<CentreRequest>(null);
    const [reviewSignals, setReviewSignals] = useState<ReviewSignal[]>(
        () => view.review,
    );
    const [colourBy, setColourBy] = useState<GraphColourBy>(() => view.colour);
    const [grouped, setGrouped] = useState(
        () => view.layout === 'constellations',
    );
    /** A traced path: its two ends and the chain between them (null when not connected). */
    const [trace, setTrace] = useState<{
        fromId: string;
        toId: string;
        ids: string[] | null;
    } | null>(() => {
        const from = nodes.find((node) => node.stable_key === focus);
        const to = nodes.find((node) => node.stable_key === view.to);

        if (from === undefined || to === undefined || from.id === to.id) {
            return null;
        }

        const areaById = new Map(nodes.map((node) => [node.id, node.area]));

        return {
            fromId: from.id,
            toId: to.id,
            ids: shortestPath(from.id, to.id, edges, (id) => {
                const area = areaById.get(id);

                return (
                    area !== undefined &&
                    (view.areas.length === 0 || view.areas.includes(area))
                );
            }),
        };
    });

    const layout = useBrainLayout(nodes, edges, grouped);
    const placedNodes = useMemo(() => layout ?? [], [layout]);
    const nodesById = useMemo(
        () => new Map(placedNodes.map((node) => [node.id, node])),
        [placedNodes],
    );
    const areas = useMemo(
        () => [...new Set(nodes.map((node) => node.area))].sort(),
        [nodes],
    );
    const hiddenAreas = useMemo(
        () =>
            shownAreas.length === 0
                ? []
                : areas.filter((area) => !shownAreas.includes(area)),
        [areas, shownAreas],
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
    const areaLabels = useMemo(
        () =>
            grouped
                ? areas.map((area) =>
                      t(`brain.areas.${area}`, { defaultValue: area }),
                  )
                : null,
        [grouped, areas, t],
    );

    const select = useCallback((id: string | null): void => {
        setSelectedId(id);
        setTrace(null);
    }, []);

    /** Shift-click: trace from the selected node to this one (or just select it). */
    const traceTo = useCallback(
        (id: string): void => {
            if (selectedId === null || selectedId === id) {
                setSelectedId(id);
                setTrace(null);

                return;
            }

            setTrace({
                fromId: selectedId,
                toId: id,
                ids: shortestPath(selectedId, id, edges, (candidate) => {
                    const node = nodesById.get(candidate);

                    return (
                        node !== undefined && !hiddenAreas.includes(node.area)
                    );
                }),
            });
            setFitRequest((request) => request + 1);
        },
        [selectedId, edges, nodesById, hiddenAreas],
    );

    const selectAndCentre = (id: string): void => {
        setSelectedId(id);
        setTrace(null);
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
            setTrace(null);
            setCentreRequest({ id: matches[0].id, nonce: Date.now() });
        }
    };

    const searchFor = (value: string): void => {
        setSearch(value);
        findMatches(value, hiddenAreas);
    };

    const showAreas = (shown: string[]): void => {
        const hidden =
            shown.length === 0
                ? []
                : areas.filter((area) => !shown.includes(area));
        setShownAreas(shown);
        setFitRequest((request) => request + 1);
        setTrace(null);

        if (selected !== null && hidden.includes(selected.area)) {
            setSelectedId(null);
        }

        if (search.trim() !== '') {
            findMatches(search, hidden);
        }
    };

    const areaOptions = useMemo<DataTableFacetedFilterOption[]>(
        () =>
            areas.map((area) => {
                const AreaDot: ComponentType<{ className?: string }> = ({
                    className,
                }) => (
                    <span
                        aria-hidden="true"
                        className={cn(
                            'flex items-center justify-center',
                            className,
                        )}
                    >
                        <span
                            className="size-2.5 rounded-full"
                            style={{
                                backgroundColor: legendColour(area, areas),
                            }}
                        />
                    </span>
                );

                return {
                    value: area,
                    label: t(`brain.areas.${area}`, { defaultValue: area }),
                    icon: AreaDot,
                };
            }),
        [areas, t],
    );
    const visibleAreas = areas.filter((area) => !hiddenAreas.includes(area));
    const hasGaps = nodes.some((node) => node.open_gaps > 0);
    const reviewOptions = useMemo<DataTableFacetedFilterOption[]>(
        () => [
            { value: 'proposals', label: t('brain.graph.reviewProposals') },
            ...(hasGaps
                ? [{ value: 'gaps', label: t('brain.graph.reviewGaps') }]
                : []),
        ],
        [hasGaps, t],
    );
    const hiddenIds = useMemo(
        () =>
            reviewSignals.length === 0
                ? null
                : nodes
                      .filter(
                          (node) =>
                              !(
                                  (reviewSignals.includes('proposals') &&
                                      node.open_proposals > 0) ||
                                  (reviewSignals.includes('gaps') &&
                                      node.open_gaps > 0)
                              ),
                      )
                      .map((node) => node.id),
        [nodes, reviewSignals],
    );
    const showReviewSignals = (values: string[]): void => {
        setReviewSignals(
            values.filter(
                (value): value is ReviewSignal =>
                    value === 'proposals' || value === 'gaps',
            ),
        );
        setFitRequest((request) => request + 1);
        setTrace(null);
    };

    const selectedKey = selected?.stable_key ?? null;
    const traceToKey =
        trace === null ? null : (nodesById.get(trace.toId)?.stable_key ?? null);

    // The view lives in the query string (#279): a client-side replace keeps the URL (and the
    // history entry Back returns to) in step without asking the server again.
    useEffect(() => {
        const query: Record<string, string> = {};

        if (selectedKey !== null) {
            query.focus = selectedKey;
        }

        if (traceToKey !== null) {
            query.to = traceToKey;
        }

        if (shownAreas.length > 0) {
            query.area = shownAreas.join(',');
        }

        if (grouped) {
            query.layout = 'constellations';
        }

        if (reviewSignals.length > 0) {
            query.review = reviewSignals.join(',');
        }

        if (colourBy === 'freshness') {
            query.colour = 'freshness';
        }

        const url = graph.url({ query });

        if (url === `${window.location.pathname}${window.location.search}`) {
            return;
        }

        router.replace({
            url,
            props: (current) => ({
                ...current,
                focus: selectedKey,
                view: {
                    areas: shownAreas,
                    layout: grouped ? 'constellations' : 'free',
                    to: traceToKey,
                    review: reviewSignals,
                    colour: colourBy,
                },
            }),
            preserveState: true,
            preserveScroll: true,
        });
    }, [selectedKey, traceToKey, shownAreas, grouped, reviewSignals, colourBy]);

    const hasGraph = hasPerimeter && nodes.length > 0;
    const traceFrom = trace === null ? undefined : nodesById.get(trace.fromId);
    const traceEnd = trace === null ? undefined : nodesById.get(trace.toId);

    return (
        <AppLayout
            breadcrumbs={[{ title: t('brain.nav.graph'), href: graph.url() }]}
        >
            <Head title={t('brain.nav.graph')} />
            <PageLayout
                className="min-h-0"
                filters={
                    hasGraph ? (
                        <div className="flex w-full min-w-0 flex-wrap items-center gap-2 sm:flex-nowrap">
                            <FilterSearch
                                value={search}
                                onChange={searchFor}
                                placeholder={t('brain.graph.searchPlaceholder')}
                            />
                            <DataTableFacetedFilter
                                title={t('brain.graph.areaFilter')}
                                ariaLabel={t('brain.graph.areaFilterLabel')}
                                options={areaOptions}
                                selectedValues={shownAreas}
                                onSelectedValuesChange={showAreas}
                            />
                            <DataTableFacetedFilter
                                title={t('brain.graph.reviewFilter')}
                                ariaLabel={t('brain.graph.reviewFilterLabel')}
                                options={reviewOptions}
                                selectedValues={reviewSignals}
                                onSelectedValuesChange={showReviewSignals}
                            />
                        </div>
                    ) : undefined
                }
                filtersRight={
                    hasGraph ? (
                        <div className="flex items-center gap-2">
                            <ToggleGroup
                                variant="outline"
                                type="single"
                                value={colourBy}
                                aria-label={t('brain.graph.colourLabel')}
                                onValueChange={(value) => {
                                    if (
                                        value === 'area' ||
                                        value === 'freshness'
                                    ) {
                                        setColourBy(value);
                                    }
                                }}
                            >
                                <SegmentedIconItem
                                    value="area"
                                    isActive={colourBy === 'area'}
                                    label={t('brain.graph.colourAreaHint')}
                                >
                                    <Palette />
                                </SegmentedIconItem>
                                <SegmentedIconItem
                                    value="freshness"
                                    isActive={colourBy === 'freshness'}
                                    label={t('brain.graph.colourFreshnessHint')}
                                >
                                    <History />
                                </SegmentedIconItem>
                            </ToggleGroup>
                            <ToggleGroup
                                variant="outline"
                                type="single"
                                value={grouped ? 'constellations' : 'free'}
                                aria-label={t('brain.graph.layoutLabel')}
                                onValueChange={(value) => {
                                    if (value) {
                                        setGrouped(value === 'constellations');
                                    }
                                }}
                            >
                                <SegmentedIconItem
                                    value="free"
                                    isActive={!grouped}
                                    label={t('brain.graph.layoutFreeHint')}
                                >
                                    <Network />
                                </SegmentedIconItem>
                                <SegmentedIconItem
                                    value="constellations"
                                    isActive={grouped}
                                    label={t('brain.graph.constellationsHint')}
                                >
                                    <Orbit />
                                </SegmentedIconItem>
                            </ToggleGroup>
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
                ) : layout === null ? (
                    <div
                        className="flex min-h-[28rem] flex-1 items-center justify-center gap-2 rounded-xl border bg-card text-sm text-muted-foreground"
                        role="status"
                    >
                        <Spinner />
                        {t('brain.graph.layingOut')}
                    </div>
                ) : (
                    <div className="relative min-h-0 flex-1">
                        <BrainGraphCanvas
                            nodes={placedNodes}
                            edges={edges}
                            areas={areas}
                            hiddenAreas={hiddenAreas}
                            matchIds={matchIds}
                            hiddenIds={hiddenIds}
                            colourBy={colourBy}
                            pathIds={trace?.ids ?? null}
                            areaLabels={areaLabels}
                            selectedId={selectedId}
                            onSelect={select}
                            onTrace={traceTo}
                            onOpen={open}
                            centreRequest={centreRequest}
                            fitRequest={fitRequest}
                        />
                        <ul
                            className="pointer-events-none absolute top-3 right-3 hidden flex-col gap-1 rounded-lg border bg-card/80 px-3 py-2 text-xs text-muted-foreground shadow-sm backdrop-blur sm:flex"
                            aria-label={t('brain.graph.legendLabel')}
                        >
                            {colourBy === 'freshness' ? (
                                <li className="flex flex-col gap-1">
                                    <span
                                        aria-hidden="true"
                                        className="h-1.5 w-32 rounded-full"
                                        style={{
                                            backgroundImage:
                                                'linear-gradient(to right, var(--muted-foreground), var(--primary))',
                                        }}
                                    />
                                    <span className="flex justify-between gap-4">
                                        <span>{t('brain.graph.freshOld')}</span>
                                        <span>{t('brain.graph.freshNew')}</span>
                                    </span>
                                </li>
                            ) : null}
                            {(colourBy === 'area' ? visibleAreas : []).map(
                                (area) => (
                                    <li
                                        key={area}
                                        className="flex items-center gap-2"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className="size-2 rounded-full"
                                            style={{
                                                backgroundColor: legendColour(
                                                    area,
                                                    areas,
                                                ),
                                            }}
                                        />
                                        <span className="flex-1">
                                            {areaLabel(area)}
                                        </span>
                                        <span className="tabular-nums">
                                            {countsByArea.get(area) ?? 0}
                                        </span>
                                    </li>
                                ),
                            )}
                            {nodes.some(
                                (node) =>
                                    node.open_proposals + node.open_gaps > 0,
                            ) ? (
                                <li className="flex items-center gap-2 border-t pt-1">
                                    <span
                                        aria-hidden="true"
                                        className="size-2 rounded-full bg-warning"
                                    />
                                    <span className="flex-1">
                                        {t('brain.graph.reviewLegend')}
                                    </span>
                                </li>
                            ) : null}
                        </ul>
                        {matchIds !== null &&
                        selected === null &&
                        trace === null ? (
                            <p
                                className="absolute top-3 left-3 rounded-full border bg-card/90 px-3 py-1 text-xs text-muted-foreground shadow-sm backdrop-blur"
                                aria-live="polite"
                            >
                                {t('brain.graph.matches', {
                                    count: matchIds.length,
                                })}
                            </p>
                        ) : null}
                        {traceFrom && traceEnd && trace !== null ? (
                            <GraphPathCard
                                from={traceFrom}
                                to={traceEnd}
                                steps={
                                    trace.ids === null
                                        ? null
                                        : trace.ids.flatMap((id) => {
                                              const node = nodesById.get(id);

                                              return node ? [node] : [];
                                          })
                                }
                                areaColour={(area) => legendColour(area, areas)}
                                onFocus={(id) =>
                                    setCentreRequest({ id, nonce: Date.now() })
                                }
                                onClose={() => setTrace(null)}
                            />
                        ) : selected ? (
                            <GraphItemCard
                                item={selected}
                                neighbours={neighbours}
                                areaLabel={areaLabel}
                                areaColour={(area) => legendColour(area, areas)}
                                onSelectNeighbour={selectAndCentre}
                                onClose={() => select(null)}
                            />
                        ) : null}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
