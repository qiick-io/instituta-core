import {
    forceCenter,
    forceCollide,
    forceLink,
    forceManyBody,
    forceSimulation,
    forceX,
    forceY,
    type SimulationLinkDatum,
    type SimulationNodeDatum,
} from 'd3-force';

export type BrainGraphNodeInput = {
    id: string;
    title: string;
    stable_key: string;
    area: string;
    status: string;
    degree: number;
};

export type BrainGraphEdgeInput = {
    from: string;
    to: string;
};

export type PlacedBrainNode = BrainGraphNodeInput & {
    x: number;
    y: number;
    radius: number;
};

type SimulatedNode = SimulationNodeDatum & {
    id: string;
    radius: number;
    isLinked: boolean;
};

/**
 * A node's radius: bigger for items with more links, so hubs stand out.
 */
export function brainNodeRadius(degree: number): number {
    return 6 + Math.min(18, Math.sqrt(degree) * 4);
}

/**
 * Lays the whole brain out with a force simulation (#254): links pull related items together,
 * linked nodes push each other away, collision keeps every node apart, and a pull to the centre
 * keeps unlinked items in a compact cloud near the rest. Run to rest up front, so the graph
 * appears settled and the same data always gives the same picture; the canvas only adds a small float on top (#270).
 */
export function layoutBrainGraph(
    nodes: BrainGraphNodeInput[],
    edges: BrainGraphEdgeInput[],
): PlacedBrainNode[] {
    const simulatedNodes: SimulatedNode[] = nodes.map((node, index) => {
        const angle = (index / Math.max(1, nodes.length)) * Math.PI * 2;

        return {
            id: node.id,
            radius: brainNodeRadius(node.degree),
            isLinked: node.degree > 0,
            x: Math.cos(angle) * 300,
            y: Math.sin(angle) * 300,
        };
    });
    const links: SimulationLinkDatum<SimulatedNode>[] = edges.map((edge) => ({
        source: edge.from,
        target: edge.to,
    }));

    const simulation = forceSimulation(simulatedNodes)
        .force(
            'link',
            forceLink<SimulatedNode, SimulationLinkDatum<SimulatedNode>>(links)
                .id((node) => node.id)
                .distance(80)
                .strength(0.4),
        )
        // Linked items push harder so clusters stay readable; unlinked ones barely push and are
        // pulled in tight, so hundreds of loose files do not dwarf the linked part of the brain.
        .force(
            'charge',
            forceManyBody<SimulatedNode>()
                .strength((node) => (node.isLinked ? -90 : -12))
                .distanceMax(400),
        )
        .force(
            'collide',
            forceCollide<SimulatedNode>().radius((node) => node.radius + 10),
        )
        .force(
            'x',
            forceX<SimulatedNode>().strength((node) =>
                node.isLinked ? 0.05 : 0.12,
            ),
        )
        .force(
            'y',
            forceY<SimulatedNode>().strength((node) =>
                node.isLinked ? 0.05 : 0.12,
            ),
        )
        .force('center', forceCenter(0, 0))
        .stop();

    const ticks = Math.ceil(
        Math.log(simulation.alphaMin()) / Math.log(1 - simulation.alphaDecay()),
    );

    for (let tick = 0; tick < ticks; tick++) {
        simulation.tick();
    }

    const positionsById = new Map(
        simulatedNodes.map((node) => [node.id, node]),
    );

    return nodes.map((node) => {
        const placed = positionsById.get(node.id);

        return {
            ...node,
            x: placed?.x ?? 0,
            y: placed?.y ?? 0,
            radius: brainNodeRadius(node.degree),
        };
    });
}
