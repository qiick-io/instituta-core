/**
 * Simple radial layout for scoped brain graph neighbours (#49).
 * Groups nodes by area around the root; depth 1 closer, depth 2 further out.
 * No second layout library.
 */

export type GraphNodeInput = {
    id: string;
    title: string;
    area: string | null;
};

export type GraphEdgeInput = {
    from: string;
    to: string;
    relation: string | null;
};

export type LaidOutNode = GraphNodeInput & {
    position: { x: number; y: number };
    depth: number;
};

/**
 * @returns Nodes with positions and BFS depth from the root.
 */
export function layoutBrainGraphRadial(
    rootId: string,
    nodes: GraphNodeInput[],
    edges: GraphEdgeInput[],
): LaidOutNode[] {
    const depthById = new Map<string, number>([[rootId, 0]]);
    const adjacency = new Map<string, string[]>();

    for (const edge of edges) {
        const outgoing = adjacency.get(edge.from) ?? [];
        outgoing.push(edge.to);
        adjacency.set(edge.from, outgoing);
    }

    const queue = [rootId];
    while (queue.length > 0) {
        const current = queue.shift();
        if (current === undefined) {
            break;
        }

        const currentDepth = depthById.get(current) ?? 0;
        for (const next of adjacency.get(current) ?? []) {
            if (depthById.has(next)) {
                continue;
            }
            depthById.set(next, currentDepth + 1);
            queue.push(next);
        }
    }

    const areas = [
        ...new Set(
            nodes
                .filter((node) => node.id !== rootId)
                .map((node) => node.area ?? 'notes'),
        ),
    ].sort();

    const areaIndex = new Map(areas.map((area, index) => [area, index]));
    const areaCounts = new Map<string, number>();
    const areaSeen = new Map<string, number>();

    for (const node of nodes) {
        if (node.id === rootId) {
            continue;
        }
        const area = node.area ?? 'notes';
        areaCounts.set(area, (areaCounts.get(area) ?? 0) + 1);
    }

    const laidOut: LaidOutNode[] = [];

    for (const node of nodes) {
        if (node.id === rootId) {
            laidOut.push({
                ...node,
                position: { x: 0, y: 0 },
                depth: 0,
            });
            continue;
        }

        const area = node.area ?? 'notes';
        const sector = areaIndex.get(area) ?? 0;
        const sectors = Math.max(areas.length, 1);
        const countInArea = areaCounts.get(area) ?? 1;
        const indexInArea = areaSeen.get(area) ?? 0;
        areaSeen.set(area, indexInArea + 1);

        const sectorStart = (sector / sectors) * Math.PI * 2 - Math.PI / 2;
        const sectorWidth = (Math.PI * 2) / sectors;
        const angle =
            sectorStart +
            ((indexInArea + 0.5) / countInArea) * sectorWidth * 0.85 +
            sectorWidth * 0.075;

        const depth = depthById.get(node.id) ?? 1;
        const radius = depth === 1 ? 220 : 360;

        laidOut.push({
            ...node,
            position: {
                x: Math.cos(angle) * radius,
                y: Math.sin(angle) * radius,
            },
            depth,
        });
    }

    for (const node of nodes) {
        if (laidOut.some((laid) => laid.id === node.id)) {
            continue;
        }
        laidOut.push({
            ...node,
            position: { x: laidOut.length * 160, y: 420 },
            depth: 2,
        });
    }

    return laidOut;
}
