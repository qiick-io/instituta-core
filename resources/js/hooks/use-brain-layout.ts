import { useEffect, useRef, useState } from 'react';
import {
    type BrainGraphEdgeInput,
    type BrainGraphNodeInput,
    layoutBrainGraph,
    type PlacedBrainNode,
} from '@/lib/brain-force-layout';

type Layouts = {
    nodes: BrainGraphNodeInput[] | null;
    free: PlacedBrainNode[] | null;
    grouped: PlacedBrainNode[] | null;
};

/**
 * The brain laid out for the graph (#282), computed in a Web Worker so a big brain does not
 * freeze the page; where workers are not available it runs on the main thread. Both layouts are
 * kept once computed. While the constellations layout is on its way the free one stays on screen,
 * so the canvas can glide from one to the other. Null until the first layout arrives.
 */
export function useBrainLayout(
    nodes: BrainGraphNodeInput[],
    edges: BrainGraphEdgeInput[],
    grouped: boolean,
): PlacedBrainNode[] | null {
    const [layouts, setLayouts] = useState<Layouts>({
        nodes: null,
        free: null,
        grouped: null,
    });
    const workerRef = useRef<Worker | null>(null);
    const requestId = useRef(0);

    // New items: start over.
    const current: Layouts =
        layouts.nodes === nodes
            ? layouts
            : { nodes, free: null, grouped: null };
    const wanted: 'free' | 'grouped' | null =
        current.free === null
            ? 'free'
            : grouped && current.grouped === null
              ? 'grouped'
              : null;

    useEffect(() => {
        if (wanted === null || nodes.length === 0) {
            return;
        }

        const options = { groupByArea: wanted === 'grouped' };
        const store = (placed: PlacedBrainNode[]): void =>
            setLayouts((previous) => {
                const base =
                    previous.nodes === nodes
                        ? previous
                        : { nodes, free: null, grouped: null };

                return { ...base, nodes, [wanted]: placed };
            });

        if (typeof Worker === 'undefined') {
            store(layoutBrainGraph(nodes, edges, options));

            return;
        }

        workerRef.current ??= new Worker(
            new URL('../lib/brain-layout.worker.ts', import.meta.url),
            { type: 'module' },
        );
        const worker = workerRef.current;
        const id = ++requestId.current;
        const onMessage = (
            event: MessageEvent<{ id: number; placed: PlacedBrainNode[] }>,
        ): void => {
            if (event.data.id === id) {
                store(event.data.placed);
            }
        };
        const onError = (): void =>
            store(layoutBrainGraph(nodes, edges, options));

        worker.addEventListener('message', onMessage);
        worker.addEventListener('error', onError);
        worker.postMessage({ id, nodes, edges, options });

        return () => {
            worker.removeEventListener('message', onMessage);
            worker.removeEventListener('error', onError);
        };
    }, [wanted, nodes, edges]);

    useEffect(
        () => () => {
            workerRef.current?.terminate();
            workerRef.current = null;
        },
        [],
    );

    if (nodes.length === 0) {
        return [];
    }

    return grouped ? (current.grouped ?? current.free) : current.free;
}
