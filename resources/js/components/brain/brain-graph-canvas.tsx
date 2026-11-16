/**
 * The whole brain as a graph (#254): every item a round node ("neuron") coloured by area and
 * sized by its links, laid out by `layoutBrainGraph` (d3-force) and drawn with @xyflow/react
 * (https://reactflow.dev/learn). Selecting a node lights it and its neighbours and dims the rest;
 * double click or Enter opens the item. Theme tokens only.
 */
import { router } from '@inertiajs/react';
import {
    Background,
    Controls,
    type Edge,
    Handle,
    type Node,
    type NodeProps,
    Position,
    ReactFlow,
    ReactFlowProvider,
    useReactFlow,
} from '@xyflow/react';
import { useEffect, useMemo } from 'react';
import type * as React from 'react';
import { useTranslation } from 'react-i18next';
import type {
    BrainGraphEdgeInput,
    PlacedBrainNode,
} from '@/lib/brain-force-layout';
import { cn } from '@/lib/utils';
import { show as explorerShow } from '@/routes/explorer';
import '@xyflow/react/dist/style.css';

/** Area colours: the chart tokens, in a fixed order so an area keeps its colour. */
const AREA_COLOURS = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
];

export function areaColour(area: string, areas: string[]): string {
    const index = Math.max(0, areas.indexOf(area));

    return AREA_COLOURS[index % AREA_COLOURS.length];
}

type NeuronData = {
    title: string;
    stableKey: string;
    radius: number;
    colour: string;
    isLabelled: boolean;
    isDimmed: boolean;
    isSelected: boolean;
};

type NeuronNode = Node<NeuronData, 'neuron'>;

/** Hidden handles at the centre, so straight edges join the circles' centres. */
const CENTRE_HANDLE_STYLE = {
    top: '50%',
    left: '50%',
    opacity: 0,
    border: 0,
    width: 1,
    height: 1,
    minWidth: 1,
    minHeight: 1,
};

function Neuron({ data }: NodeProps<NeuronNode>) {
    return (
        <div
            className={cn(
                'relative flex items-center justify-center transition-opacity',
                data.isDimmed && 'opacity-20',
            )}
            style={{ width: data.radius * 2, height: data.radius * 2 }}
        >
            <Handle
                type="target"
                position={Position.Top}
                style={CENTRE_HANDLE_STYLE}
            />
            <div
                className={cn(
                    'size-full rounded-full border-2 border-background shadow-sm',
                    data.isSelected &&
                        'ring-2 ring-ring ring-offset-2 ring-offset-background',
                )}
                style={{ backgroundColor: data.colour }}
                title={data.title}
            />
            {data.isLabelled ? (
                <span className="pointer-events-none absolute top-full mt-1 max-w-48 truncate rounded bg-background/80 px-1 text-xs text-foreground">
                    {data.title}
                </span>
            ) : null}
            <Handle
                type="source"
                position={Position.Top}
                style={CENTRE_HANDLE_STYLE}
            />
        </div>
    );
}

const NODE_TYPES = { neuron: Neuron };

/** @xyflow/react styles its controls and background through CSS variables: map them to tokens. */
const XYFLOW_THEME = {
    '--xy-background-color': 'var(--card)',
    '--xy-background-pattern-color': 'var(--border)',
    '--xy-controls-button-background-color': 'var(--card)',
    '--xy-controls-button-background-color-hover': 'var(--muted)',
    '--xy-controls-button-color': 'var(--foreground)',
    '--xy-controls-button-color-hover': 'var(--foreground)',
    '--xy-controls-button-border-color': 'var(--border)',
    '--xy-controls-box-shadow': 'none',
} as React.CSSProperties;

/** Hubs keep their label at every zoom; the others show it when selected or neighbouring. */
const LABELLED_DEGREE = 6;

function GraphCanvas({
    nodes,
    edges,
    areas,
    selectedId,
    onSelect,
}: {
    nodes: PlacedBrainNode[];
    edges: BrainGraphEdgeInput[];
    areas: string[];
    selectedId: string | null;
    onSelect: (id: string | null) => void;
}) {
    const { t } = useTranslation();
    const { fitView } = useReactFlow();

    const neighbourIds = useMemo(() => {
        if (selectedId === null) {
            return null;
        }

        const ids = new Set<string>([selectedId]);

        for (const edge of edges) {
            if (edge.from === selectedId) {
                ids.add(edge.to);
            } else if (edge.to === selectedId) {
                ids.add(edge.from);
            }
        }

        return ids;
    }, [edges, selectedId]);

    const flowNodes = useMemo<NeuronNode[]>(
        () =>
            nodes.map((node) => ({
                id: node.id,
                type: 'neuron',
                position: { x: node.x - node.radius, y: node.y - node.radius },
                draggable: false,
                ariaLabel: node.title,
                data: {
                    title: node.title,
                    stableKey: node.stable_key,
                    radius: node.radius,
                    colour: areaColour(node.area, areas),
                    isLabelled:
                        node.degree >= LABELLED_DEGREE ||
                        (neighbourIds?.has(node.id) ?? false),
                    isDimmed:
                        neighbourIds !== null && !neighbourIds.has(node.id),
                    isSelected: node.id === selectedId,
                },
            })),
        [areas, neighbourIds, nodes, selectedId],
    );

    const flowEdges = useMemo<Edge[]>(
        () =>
            edges.map((edge) => {
                const isLit =
                    selectedId !== null &&
                    (edge.from === selectedId || edge.to === selectedId);

                return {
                    id: `${edge.from}-${edge.to}`,
                    source: edge.from,
                    target: edge.to,
                    type: 'straight',
                    style: {
                        stroke: isLit ? 'var(--primary)' : 'var(--border)',
                        strokeWidth: isLit ? 1.5 : 1,
                        opacity: selectedId !== null && !isLit ? 0.25 : 1,
                    },
                };
            }),
        [edges, selectedId],
    );

    // Centre the view on the selected node (search, a link with ?focus=).
    useEffect(() => {
        if (selectedId !== null) {
            void fitView({
                nodes: [{ id: selectedId }],
                maxZoom: 1.4,
                duration: 300,
            });
        }
    }, [fitView, selectedId]);

    return (
        <ReactFlow
            nodes={flowNodes}
            edges={flowEdges}
            nodeTypes={NODE_TYPES}
            fitView
            minZoom={0.1}
            maxZoom={3}
            nodesConnectable={false}
            nodesDraggable={false}
            onNodeClick={(_, node) => onSelect(node.id)}
            onNodeDoubleClick={(_, node) =>
                router.visit(
                    explorerShow.url((node.data as NeuronData).stableKey),
                )
            }
            onPaneClick={() => onSelect(null)}
            proOptions={{ hideAttribution: true }}
            aria-label={t('brain.graph.canvasLabel')}
        >
            <Background gap={24} size={1} />
            <Controls showInteractive={false} />
        </ReactFlow>
    );
}

export function BrainGraphCanvas(props: {
    nodes: PlacedBrainNode[];
    edges: BrainGraphEdgeInput[];
    areas: string[];
    selectedId: string | null;
    onSelect: (id: string | null) => void;
}) {
    return (
        <div
            className="h-full min-h-[28rem] w-full overflow-hidden rounded-xl border bg-card"
            style={XYFLOW_THEME}
        >
            <ReactFlowProvider>
                <GraphCanvas {...props} />
            </ReactFlowProvider>
        </div>
    );
}
