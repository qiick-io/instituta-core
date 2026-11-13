/**
 * Scoped neighbour canvas for #49.
 * Source: https://reactflow.dev/learn (@xyflow/react).
 * Colours overridden with theme tokens only (no library hex in className).
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
} from '@xyflow/react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import {
    type GraphEdgeInput,
    type GraphNodeInput,
    layoutBrainGraphRadial,
} from '@/lib/brain-graph-layout';
import { cn } from '@/lib/utils';
import '@xyflow/react/dist/style.css';
import { reading } from '@/routes/brain';

type BrainGraphNodeData = {
    title: string;
    area: string | null;
    isRoot: boolean;
};

type BrainGraphFlowNode = Node<BrainGraphNodeData, 'brain'>;

function BrainGraphNode({ id, data, selected }: NodeProps<BrainGraphFlowNode>) {
    const { t } = useTranslation();

    return (
        <div
            className={cn(
                'max-w-56 min-w-40 rounded-lg border bg-card px-3 py-2 text-left shadow-sm outline-none',
                'border-border text-card-foreground focus-visible:ring-2 focus-visible:ring-ring',
                data.isRoot && 'border-primary ring-2 ring-primary/30',
                selected && 'ring-2 ring-ring',
            )}
            tabIndex={0}
            role="link"
            aria-label={data.title}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    router.visit(reading.url(id));
                }
            }}
        >
            <Handle
                type="target"
                position={Position.Left}
                className="size-2 border-border bg-muted-foreground"
            />
            <p className="truncate text-sm font-medium">{data.title}</p>
            {data.area ? (
                <p className="truncate text-xs text-muted-foreground">
                    {t(`brain.areas.${data.area}`, { defaultValue: data.area })}
                </p>
            ) : null}
            <Handle
                type="source"
                position={Position.Right}
                className="size-2 border-border bg-muted-foreground"
            />
        </div>
    );
}

const nodeTypes = { brain: BrainGraphNode };

type Props = {
    rootId: string;
    nodes: GraphNodeInput[];
    edges: GraphEdgeInput[];
};

function BrainGraphCanvasInner({ rootId, nodes, edges }: Props) {
    const { t } = useTranslation();
    const laidOut = useMemo(
        () => layoutBrainGraphRadial(rootId, nodes, edges),
        [rootId, nodes, edges],
    );

    const flowNodes: BrainGraphFlowNode[] = useMemo(
        () =>
            laidOut.map((node) => ({
                id: node.id,
                type: 'brain' as const,
                position: node.position,
                data: {
                    title: node.title,
                    area: node.area,
                    isRoot: node.id === rootId,
                },
                draggable: false,
            })),
        [laidOut, rootId],
    );

    const flowEdges: Edge[] = useMemo(
        () =>
            edges.map((edge) => ({
                id: `${edge.from}-${edge.to}-${edge.relation ?? 'link'}`,
                source: edge.from,
                target: edge.to,
                label: edge.relation ?? undefined,
                style: { stroke: 'var(--border)' },
                labelStyle: { fill: 'var(--muted-foreground)', fontSize: 10 },
            })),
        [edges],
    );

    return (
        <div
            className="brain-graph-canvas h-[32rem] w-full overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            role="application"
            aria-label={t('brain.graph.canvasLabel')}
        >
            <ReactFlow
                nodes={flowNodes}
                edges={flowEdges}
                nodeTypes={nodeTypes}
                fitView
                nodesDraggable={false}
                nodesConnectable={false}
                elementsSelectable
                panOnScroll
                zoomOnScroll
                minZoom={0.4}
                maxZoom={1.5}
                proOptions={{ hideAttribution: true }}
                onNodeClick={(_event, node) => {
                    router.visit(reading.url(node.id));
                }}
            >
                <Background
                    gap={20}
                    color="var(--border)"
                    className="bg-background!"
                />
                <Controls
                    showInteractive={false}
                    className="overflow-hidden! rounded-md! border! border-border! bg-card! shadow-none!"
                />
            </ReactFlow>
        </div>
    );
}

/**
 * Provider wrapper so Controls can access the flow store.
 */
export function BrainGraphCanvas(props: Props) {
    return (
        <ReactFlowProvider>
            <BrainGraphCanvasInner {...props} />
        </ReactFlowProvider>
    );
}
