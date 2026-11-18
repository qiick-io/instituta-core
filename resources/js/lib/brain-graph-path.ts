import type { BrainGraphEdgeInput } from '@/lib/brain-force-layout';

/**
 * The shortest chain of links from one item to another (#275), as item ids from `fromId` to
 * `toId`; null when they are not connected. Breadth-first, so every link counts as one step.
 * Items for which `isAllowed` is false (hidden areas) are not walked through.
 */
export function shortestPath(
    fromId: string,
    toId: string,
    edges: BrainGraphEdgeInput[],
    isAllowed: (id: string) => boolean,
): string[] | null {
    if (fromId === toId) {
        return [fromId];
    }

    const adjacency = new Map<string, string[]>();
    const connect = (fromItem: string, toItem: string): void => {
        const list = adjacency.get(fromItem) ?? [];
        list.push(toItem);
        adjacency.set(fromItem, list);
    };

    for (const edge of edges) {
        connect(edge.from, edge.to);
        connect(edge.to, edge.from);
    }

    const previous = new Map<string, string | null>([[fromId, null]]);
    const queue = [fromId];

    for (let head = 0; head < queue.length; head++) {
        const current = queue[head];

        for (const next of adjacency.get(current) ?? []) {
            if (previous.has(next) || !isAllowed(next)) {
                continue;
            }

            previous.set(next, current);

            if (next === toId) {
                const path = [toId];
                let step = previous.get(toId) ?? null;

                while (step !== null) {
                    path.unshift(step);
                    step = previous.get(step) ?? null;
                }

                return path;
            }

            queue.push(next);
        }
    }

    return null;
}
