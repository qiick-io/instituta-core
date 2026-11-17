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
    anchorX: number;
    anchorY: number;
    area: string;
};

export type BrainLayoutOptions = {
    /** Pull each area to its own anchor on a ring, so the areas read as separate constellations. */
    groupByArea?: boolean;
};

/**
 * Where each area's constellation sits: on a ring wide enough that the biggest clusters do not
 * touch. One area stays in the middle.
 */
function areaAnchors(
    nodes: BrainGraphNodeInput[],
): Map<string, { x: number; y: number }> {
    const counts = new Map<string, number>();

    for (const node of nodes) {
        counts.set(node.area, (counts.get(node.area) ?? 0) + 1);
    }

    const areas = [...counts.keys()].sort();
    const anchors = new Map<string, { x: number; y: number }>();

    if (areas.length <= 1) {
        areas.forEach((area) => anchors.set(area, { x: 0, y: 0 }));

        return anchors;
    }

    const largestCluster = Math.max(
        ...[...counts.values()].map((count) => Math.sqrt(count) * 20),
    );
    const ring = Math.max(
        260,
        (largestCluster * 1.25) / Math.sin(Math.PI / areas.length),
    );

    areas.forEach((area, index) => {
        const angle = (index / areas.length) * Math.PI * 2 - Math.PI / 2;
        anchors.set(area, {
            x: Math.cos(angle) * ring,
            y: Math.sin(angle) * ring,
        });
    });

    return anchors;
}

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
    options: BrainLayoutOptions = {},
): PlacedBrainNode[] {
    const grouped = options.groupByArea === true;
    const anchors = grouped ? areaAnchors(nodes) : new Map();
    const simulatedNodes: SimulatedNode[] = nodes.map((node, index) => {
        const angle = (index / Math.max(1, nodes.length)) * Math.PI * 2;
        const anchor = anchors.get(node.area) ?? { x: 0, y: 0 };

        return {
            id: node.id,
            radius: brainNodeRadius(node.degree),
            isLinked: node.degree > 0,
            area: node.area,
            anchorX: anchor.x,
            anchorY: anchor.y,
            x: anchor.x + Math.cos(angle) * (grouped ? 80 : 300),
            y: anchor.y + Math.sin(angle) * (grouped ? 80 : 300),
        };
    });
    const areaById = new Map(nodes.map((node) => [node.id, node.area]));
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
                // Grouped, links across areas barely pull, so each constellation keeps its shape.
                .strength((link) =>
                    grouped &&
                    areaById.get((link.source as SimulatedNode).id) !==
                        areaById.get((link.target as SimulatedNode).id)
                        ? 0.01
                        : 0.4,
                ),
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
            forceX<SimulatedNode>((node) => node.anchorX).strength((node) =>
                grouped ? 0.2 : node.isLinked ? 0.05 : 0.12,
            ),
        )
        .force(
            'y',
            forceY<SimulatedNode>((node) => node.anchorY).strength((node) =>
                grouped ? 0.2 : node.isLinked ? 0.05 : 0.12,
            ),
        )
        .force('center', grouped ? null : forceCenter(0, 0))
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
