/**
 * Runs the brain layout off the main thread (#282): d3-force over a few thousand items takes long
 * enough to freeze the page, so the graph page posts the items here and draws when the places
 * come back. Each request carries an id; the answer echoes it.
 */
import {
    type BrainGraphEdgeInput,
    type BrainGraphNodeInput,
    type BrainLayoutOptions,
    layoutBrainGraph,
} from '@/lib/brain-force-layout';

export type BrainLayoutRequest = {
    id: number;
    nodes: BrainGraphNodeInput[];
    edges: BrainGraphEdgeInput[];
    options: BrainLayoutOptions;
};

type WorkerScope = {
    onmessage: ((event: MessageEvent<BrainLayoutRequest>) => void) | null;
    postMessage: (message: unknown) => void;
};

const scope = self as unknown as WorkerScope;

scope.onmessage = (event) => {
    const { id, nodes, edges, options } = event.data;
    scope.postMessage({ id, placed: layoutBrainGraph(nodes, edges, options) });
};
