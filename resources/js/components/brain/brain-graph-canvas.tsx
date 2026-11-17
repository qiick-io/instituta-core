/**
 * The whole brain as a living graph (#254, #270), drawn on a canvas so hundreds of nodes stay
 * smooth. Positions come from `layoutBrainGraph` (d3-force, settled once); on top of them every
 * node floats a few pixels on its own slow loop, and a handful of beams run along the links.
 *
 * - Click selects a node: its neighbours light up, the rest dims, and beams run only along the
 *   selected node's links (at most MAX_SELECTED_BEAMS, so it stays fast). Click empty space to clear.
 * - Double click (or Enter on the focused canvas) opens the item.
 * - Drag to pan, wheel to zoom, the buttons zoom and fit.
 * - Labels only where they fit: the selection, the hovered node and the hubs, more as you zoom in,
 *   never overlapping.
 * - Reduced motion: no float, no beams, camera jumps instead of gliding; the canvas redraws only
 *   when something changes. Colours come from the theme tokens and follow light/dark.
 */
import { Maximize, Minus, Plus } from 'lucide-react';
import { useCallback, useEffect, useRef } from 'react';
import type * as React from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import type {
    BrainGraphEdgeInput,
    PlacedBrainNode,
} from '@/lib/brain-force-layout';
import {
    areaIndex,
    boxesOverlap,
    floatOffset,
    type LabelBox,
    type Palette,
    readPalette,
    rgba,
    truncateLabel,
} from '@/lib/brain-graph-render';

/** Hubs keep their label at every zoom; the others show it when selected, neighbouring or hovered. */
const LABELLED_DEGREE = 6;
/** From this zoom on, every node may show its label (where it fits). */
const ALL_LABELS_ZOOM = 1.8;
const MAX_SELECTED_BEAMS = 16;
const AMBIENT_BEAMS = 5;
const FLOAT_AMPLITUDE = 3;
const MIN_ZOOM = 0.08;
const MAX_ZOOM = 4;
const LABEL_FONT_SIZE = 12;

type Camera = { x: number; y: number; k: number };

export type CentreRequest = { id: string; nonce: number } | null;

type Props = {
    nodes: PlacedBrainNode[];
    edges: BrainGraphEdgeInput[];
    areas: string[];
    selectedId: string | null;
    onSelect: (id: string | null) => void;
    onOpen: (node: PlacedBrainNode) => void;
    /** Glide the camera to this node; a new nonce asks again for the same node. */
    centreRequest: CentreRequest;
};

/** Everything the frame loop reads, kept in a ref so drawing never re-renders React. */
type Scene = {
    nodes: PlacedBrainNode[];
    indexById: Map<string, number>;
    links: Array<[number, number]>;
    nodeArea: number[];
    neighbours: Set<number> | null;
    selected: number | null;
    hovered: number | null;
    px: Float32Array;
    py: Float32Array;
    camera: Camera;
    target: Camera | null;
    width: number;
    height: number;
    dpr: number;
    palette: Palette | null;
    animate: boolean;
    dirty: boolean;
    fitted: boolean;
    labelWidths: Map<number, number>;
};

function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/** A small deterministic hash, so ambient beams pick edges without any state. */
function hash(value: number): number {
    const x = Math.sin(value * 12.9898) * 43758.5453;

    return x - Math.floor(x);
}

function fitCamera(scene: Scene): Camera {
    if (scene.nodes.length === 0 || scene.width === 0) {
        return { x: scene.width / 2, y: scene.height / 2, k: 1 };
    }

    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;

    for (const node of scene.nodes) {
        minX = Math.min(minX, node.x - node.radius);
        minY = Math.min(minY, node.y - node.radius);
        maxX = Math.max(maxX, node.x + node.radius);
        maxY = Math.max(maxY, node.y + node.radius);
    }

    const padding = 48;
    const k = Math.min(
        1.4,
        Math.max(
            MIN_ZOOM,
            Math.min(
                (scene.width - padding * 2) / Math.max(1, maxX - minX),
                (scene.height - padding * 2) / Math.max(1, maxY - minY),
            ),
        ),
    );

    return {
        x: scene.width / 2 - ((minX + maxX) / 2) * k,
        y: scene.height / 2 - ((minY + maxY) / 2) * k,
        k,
    };
}

function cameraOn(scene: Scene, index: number): Camera {
    const node = scene.nodes[index];
    const k = Math.max(scene.camera.k, 1.2);
    // The side card covers the top left: keep the node a little right of centre on wide canvases.
    const centreX = scene.width > 720 ? scene.width / 2 + 120 : scene.width / 2;

    return {
        x: centreX - node.x * k,
        y: scene.height / 2 - node.y * k,
        k,
    };
}

function draw(
    context: CanvasRenderingContext2D,
    scene: Scene,
    time: number,
): void {
    const palette = scene.palette;

    if (palette === null) {
        return;
    }

    const { camera, nodes, links, px, py } = scene;
    const hasSelection = scene.selected !== null;

    context.setTransform(scene.dpr, 0, 0, scene.dpr, 0, 0);
    context.clearRect(0, 0, scene.width, scene.height);

    for (let index = 0; index < nodes.length; index++) {
        const offset = scene.animate
            ? floatOffset(index, time, FLOAT_AMPLITUDE)
            : { dx: 0, dy: 0 };
        px[index] = nodes[index].x + offset.dx;
        py[index] = nodes[index].y + offset.dy;
    }

    context.save();
    context.translate(camera.x, camera.y);
    context.scale(camera.k, camera.k);
    context.lineCap = 'round';

    // Links: one path for the quiet ones, one for the selected node's.
    const isLit = (from: number, to: number): boolean =>
        hasSelection && (from === scene.selected || to === scene.selected);

    context.beginPath();

    for (const [from, to] of links) {
        if (!isLit(from, to)) {
            context.moveTo(px[from], py[from]);
            context.lineTo(px[to], py[to]);
        }
    }

    context.lineWidth = 1 / camera.k;
    context.strokeStyle = rgba(palette.border, hasSelection ? 0.25 : 0.75);
    context.stroke();

    const litLinks = hasSelection
        ? links.filter(([from, to]) => isLit(from, to))
        : [];

    if (litLinks.length > 0) {
        context.beginPath();

        for (const [from, to] of litLinks) {
            context.moveTo(px[from], py[from]);
            context.lineTo(px[to], py[to]);
        }

        context.lineWidth = 1.5 / camera.k;
        context.strokeStyle = rgba(palette.primary, 0.45);
        context.stroke();
    }

    if (scene.animate) {
        drawBeams(context, scene, palette, litLinks, time);
    }

    // Nodes: a soft halo for the ones that matter, then the body.
    for (let index = 0; index < nodes.length; index++) {
        const node = nodes[index];
        const colour = palette.areas[scene.nodeArea[index]];
        const isNeighbour = scene.neighbours?.has(index) ?? false;
        const isDimmed = hasSelection && !isNeighbour;
        const isHighlighted =
            index === scene.selected ||
            index === scene.hovered ||
            (hasSelection ? isNeighbour : node.degree >= LABELLED_DEGREE);
        const alpha = isDimmed ? 0.16 : 1;

        if (isHighlighted && !isDimmed) {
            const halo = context.createRadialGradient(
                px[index],
                py[index],
                node.radius * 0.6,
                px[index],
                py[index],
                node.radius * 2.4,
            );
            halo.addColorStop(
                0,
                rgba(
                    colour,
                    hasSelection || index === scene.hovered ? 0.28 : 0.14,
                ),
            );
            halo.addColorStop(1, rgba(colour, 0));
            context.fillStyle = halo;
            context.beginPath();
            context.arc(
                px[index],
                py[index],
                node.radius * 2.4,
                0,
                Math.PI * 2,
            );
            context.fill();
        }

        context.beginPath();
        context.arc(px[index], py[index], node.radius, 0, Math.PI * 2);
        context.fillStyle = rgba(colour, 0.92 * alpha);
        context.fill();
        context.lineWidth = 1.5 / camera.k;
        context.strokeStyle = rgba(palette.card, alpha);
        context.stroke();

        if (index === scene.selected || index === scene.hovered) {
            context.beginPath();
            context.arc(
                px[index],
                py[index],
                node.radius + 4 / camera.k,
                0,
                Math.PI * 2,
            );
            context.lineWidth = 2 / camera.k;
            context.strokeStyle = rgba(
                index === scene.selected ? palette.primary : colour,
                index === scene.selected ? 0.9 : 0.6,
            );
            context.stroke();
        }
    }

    context.restore();
    drawLabels(context, scene, palette);
}

/**
 * Beams: a short glowing trail running along a link. With a selection they run outward from the
 * selected node along its links; without one, a few drift across random links to show the graph
 * is alive. Never more than MAX_SELECTED_BEAMS at once.
 */
function drawBeams(
    context: CanvasRenderingContext2D,
    scene: Scene,
    palette: Palette,
    litLinks: Array<[number, number]>,
    time: number,
): void {
    const { px, py, camera } = scene;
    const beams: Array<{
        from: number;
        to: number;
        progress: number;
        colour: Palette['primary'];
        alpha: number;
    }> = [];

    if (scene.selected !== null) {
        litLinks.slice(0, MAX_SELECTED_BEAMS).forEach(([from, to], index) => {
            const outward = from === scene.selected;
            beams.push({
                from: outward ? from : to,
                to: outward ? to : from,
                progress: (time * 0.55 + index * 0.37) % 1,
                colour: palette.primary,
                alpha: 0.95,
            });
        });
    } else if (scene.links.length > 0) {
        const period = 2.8;

        for (let beam = 0; beam < AMBIENT_BEAMS; beam++) {
            const clock = time / period + beam / AMBIENT_BEAMS;
            const cycle = Math.floor(clock);
            const link =
                scene.links[
                    Math.floor(hash(cycle * 31 + beam * 7) * scene.links.length)
                ];
            beams.push({
                from: link[0],
                to: link[1],
                progress: clock - cycle,
                colour: palette.areas[scene.nodeArea[link[0]]],
                alpha: 0.8,
            });
        }
    }

    const trail = 0.22;

    for (const beam of beams) {
        const head = beam.progress;
        const tail = Math.max(0, head - trail);
        const x1 = px[beam.from] + (px[beam.to] - px[beam.from]) * tail;
        const y1 = py[beam.from] + (py[beam.to] - py[beam.from]) * tail;
        const x2 = px[beam.from] + (px[beam.to] - px[beam.from]) * head;
        const y2 = py[beam.from] + (py[beam.to] - py[beam.from]) * head;
        // Fade in and out at the ends of the link.
        const fade = Math.min(1, head / 0.15, (1 - head) / 0.15);

        const gradient = context.createLinearGradient(x1, y1, x2, y2);
        gradient.addColorStop(0, rgba(beam.colour, 0));
        gradient.addColorStop(1, rgba(beam.colour, beam.alpha * fade));
        context.beginPath();
        context.moveTo(x1, y1);
        context.lineTo(x2, y2);
        context.lineWidth = 2.5 / camera.k;
        context.strokeStyle = gradient;
        context.stroke();

        context.beginPath();
        context.arc(x2, y2, 2.2 / camera.k, 0, Math.PI * 2);
        context.fillStyle = rgba(beam.colour, beam.alpha * fade);
        context.fill();
    }
}

/** Labels in screen space, in priority order, skipping any that would overlap a placed one. */
function drawLabels(
    context: CanvasRenderingContext2D,
    scene: Scene,
    palette: Palette,
): void {
    const { camera, nodes, px, py } = scene;
    const candidates: number[] = [];
    const seen = new Set<number>();
    const add = (index: number | null): void => {
        if (index !== null && !seen.has(index)) {
            seen.add(index);
            candidates.push(index);
        }
    };

    add(scene.selected);
    add(scene.hovered);
    [...(scene.neighbours ?? [])]
        .sort((a, b) => nodes[b].degree - nodes[a].degree)
        .forEach(add);

    const showsAll = camera.k >= ALL_LABELS_ZOOM;
    const byDegree = nodes
        .map((_, index) => index)
        .filter(
            (index) =>
                showsAll ||
                (scene.selected === null &&
                    nodes[index].degree >= LABELLED_DEGREE),
        )
        .sort((a, b) => nodes[b].degree - nodes[a].degree)
        .slice(0, 300);
    byDegree.forEach(add);

    context.font = `500 ${LABEL_FONT_SIZE}px ${palette.fontFamily}`;
    context.textBaseline = 'middle';
    context.textAlign = 'center';

    const placed: LabelBox[] = [];
    const height = LABEL_FONT_SIZE + 8;

    for (const index of candidates) {
        const label = truncateLabel(nodes[index].title);
        let textWidth = scene.labelWidths.get(index);

        if (textWidth === undefined) {
            textWidth = context.measureText(label).width;
            scene.labelWidths.set(index, textWidth);
        }

        const width = textWidth + 12;
        const centreX = px[index] * camera.k + camera.x;
        const top = (py[index] + nodes[index].radius) * camera.k + camera.y + 6;
        const box = { x: centreX - width / 2, y: top, width, height };

        if (
            box.x + width < 0 ||
            box.x > scene.width ||
            box.y + height < 0 ||
            box.y > scene.height ||
            placed.some((other) => boxesOverlap(box, other))
        ) {
            continue;
        }

        placed.push(box);
        const isFocus = index === scene.selected || index === scene.hovered;

        context.beginPath();
        context.roundRect(box.x, box.y, width, height, height / 2);
        context.fillStyle = rgba(palette.card, 0.88);
        context.fill();
        context.lineWidth = 1;
        context.strokeStyle = rgba(
            isFocus ? palette.primary : palette.border,
            isFocus ? 0.6 : 0.9,
        );
        context.stroke();
        context.fillStyle = rgba(
            isFocus ? palette.foreground : palette.mutedForeground,
            1,
        );
        context.fillText(label, centreX, top + height / 2 + 0.5);
    }
}

export function BrainGraphCanvas({
    nodes,
    edges,
    areas,
    selectedId,
    onSelect,
    onOpen,
    centreRequest,
}: Props) {
    const { t } = useTranslation();
    const containerRef = useRef<HTMLDivElement>(null);
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const sceneRef = useRef<Scene>({
        nodes: [],
        indexById: new Map(),
        links: [],
        nodeArea: [],
        neighbours: null,
        selected: null,
        hovered: null,
        px: new Float32Array(0),
        py: new Float32Array(0),
        camera: { x: 0, y: 0, k: 1 },
        target: null,
        width: 0,
        height: 0,
        dpr: 1,
        palette: null,
        animate: !prefersReducedMotion(),
        dirty: true,
        fitted: false,
        labelWidths: new Map(),
    });
    const callbacks = useRef({ onSelect, onOpen });

    useEffect(() => {
        callbacks.current = { onSelect, onOpen };
    }, [onSelect, onOpen]);

    const moveCamera = useCallback((camera: Camera): void => {
        const scene = sceneRef.current;

        if (scene.animate) {
            scene.target = camera;
        } else {
            scene.camera = camera;
            scene.target = null;
        }

        scene.dirty = true;
    }, []);

    // Data: index the nodes, resolve links to indexes, colour by area.
    useEffect(() => {
        const scene = sceneRef.current;
        scene.nodes = nodes;
        scene.indexById = new Map(nodes.map((node, index) => [node.id, index]));
        scene.links = edges.flatMap((edge): Array<[number, number]> => {
            const from = scene.indexById.get(edge.from);
            const to = scene.indexById.get(edge.to);

            return from === undefined || to === undefined ? [] : [[from, to]];
        });
        scene.nodeArea = nodes.map((node) => areaIndex(node.area, areas));
        scene.px = new Float32Array(nodes.length);
        scene.py = new Float32Array(nodes.length);
        scene.labelWidths = new Map();
        scene.fitted = false;
        scene.dirty = true;
    }, [nodes, edges, areas]);

    // Selection from the page (click, search, a neighbour in the card).
    useEffect(() => {
        const scene = sceneRef.current;
        const index =
            selectedId === null ? undefined : scene.indexById.get(selectedId);

        if (index === undefined) {
            scene.selected = null;
            scene.neighbours = null;
        } else {
            scene.selected = index;
            scene.neighbours = new Set([index]);

            for (const [from, to] of scene.links) {
                if (from === index) {
                    scene.neighbours.add(to);
                } else if (to === index) {
                    scene.neighbours.add(from);
                }
            }
        }

        scene.dirty = true;
    }, [selectedId, nodes, edges]);

    useEffect(() => {
        const scene = sceneRef.current;
        const index =
            centreRequest === null
                ? undefined
                : scene.indexById.get(centreRequest.id);

        if (index !== undefined && scene.fitted) {
            moveCamera(cameraOn(scene, index));
        }
    }, [centreRequest, moveCamera]);

    // Size, theme and motion preference.
    useEffect(() => {
        const container = containerRef.current;
        const canvas = canvasRef.current;

        if (container === null || canvas === null) {
            return;
        }

        const scene = sceneRef.current;
        scene.palette = readPalette(container);

        const resize = (): void => {
            const rect = container.getBoundingClientRect();
            scene.width = rect.width;
            scene.height = rect.height;
            scene.dpr = window.devicePixelRatio || 1;
            canvas.width = Math.round(rect.width * scene.dpr);
            canvas.height = Math.round(rect.height * scene.dpr);
            scene.dirty = true;
        };
        resize();
        const resizeObserver = new ResizeObserver(resize);
        resizeObserver.observe(container);

        // The theme switches by class on <html> (and the instance preset by a style tag).
        const themeObserver = new MutationObserver(() => {
            scene.palette = readPalette(container);
            scene.labelWidths = new Map();
            scene.dirty = true;
        });
        themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class', 'style', 'data-theme'],
        });

        const motionQuery = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        );
        const onMotionChange = (): void => {
            scene.animate = !motionQuery.matches;
            scene.dirty = true;
        };
        motionQuery.addEventListener('change', onMotionChange);

        return () => {
            resizeObserver.disconnect();
            themeObserver.disconnect();
            motionQuery.removeEventListener('change', onMotionChange);
        };
    }, []);

    // The frame loop: always draws while animating, otherwise only when something changed.
    // requestAnimationFrame pauses on its own while the tab is hidden.
    useEffect(() => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');

        if (!canvas || !context) {
            return;
        }

        let frame = 0;
        const loop = (now: number): void => {
            frame = requestAnimationFrame(loop);
            const scene = sceneRef.current;

            if (!scene.fitted && scene.width > 0 && scene.nodes.length > 0) {
                scene.camera = fitCamera(scene);
                scene.fitted = true;

                if (scene.selected !== null) {
                    scene.camera = cameraOn(scene, scene.selected);
                }

                scene.dirty = true;
            }

            if (scene.target !== null) {
                const { camera, target } = scene;
                const ease = 0.16;
                camera.x += (target.x - camera.x) * ease;
                camera.y += (target.y - camera.y) * ease;
                camera.k += (target.k - camera.k) * ease;

                if (
                    Math.abs(target.x - camera.x) < 0.5 &&
                    Math.abs(target.y - camera.y) < 0.5 &&
                    Math.abs(target.k - camera.k) < 0.001
                ) {
                    scene.camera = { ...target };
                    scene.target = null;
                }

                scene.dirty = true;
            }

            if (scene.animate || scene.dirty) {
                draw(context, scene, now / 1000);
                scene.dirty = false;
            }
        };
        frame = requestAnimationFrame(loop);

        return () => cancelAnimationFrame(frame);
    }, []);

    // Pointer: drag pans, a click without movement selects, wheel zooms around the cursor.
    useEffect(() => {
        const canvas = canvasRef.current;

        if (canvas === null) {
            return;
        }

        const scene = sceneRef.current;
        let drag: {
            x: number;
            y: number;
            camera: Camera;
            moved: boolean;
        } | null = null;

        const local = (event: MouseEvent): { x: number; y: number } => {
            const rect = canvas.getBoundingClientRect();

            return {
                x: event.clientX - rect.left,
                y: event.clientY - rect.top,
            };
        };

        const hitTest = (event: MouseEvent): number | null => {
            const point = local(event);
            const worldX = (point.x - scene.camera.x) / scene.camera.k;
            const worldY = (point.y - scene.camera.y) / scene.camera.k;
            let best: number | null = null;
            let bestDistance = Infinity;

            for (let index = 0; index < scene.nodes.length; index++) {
                const distance = Math.hypot(
                    scene.px[index] - worldX,
                    scene.py[index] - worldY,
                );
                const reach = scene.nodes[index].radius + 4 / scene.camera.k;

                if (distance <= reach && distance < bestDistance) {
                    best = index;
                    bestDistance = distance;
                }
            }

            return best;
        };

        const onPointerDown = (event: PointerEvent): void => {
            if (event.button !== 0) {
                return;
            }

            drag = {
                ...local(event),
                camera: { ...scene.camera },
                moved: false,
            };
            scene.target = null;

            // Keep the drag when the pointer leaves the canvas; some synthetic pointers cannot be captured.
            try {
                canvas.setPointerCapture(event.pointerId);
            } catch {
                // The drag still works while the pointer stays over the canvas.
            }
        };

        const onPointerMove = (event: PointerEvent): void => {
            if (drag !== null) {
                const point = local(event);
                const dx = point.x - drag.x;
                const dy = point.y - drag.y;

                if (drag.moved || Math.hypot(dx, dy) > 3) {
                    drag.moved = true;
                    scene.camera = {
                        ...drag.camera,
                        x: drag.camera.x + dx,
                        y: drag.camera.y + dy,
                    };
                    canvas.style.cursor = 'grabbing';
                    scene.dirty = true;
                }

                return;
            }

            const hovered = hitTest(event);

            if (hovered !== scene.hovered) {
                scene.hovered = hovered;
                canvas.style.cursor = hovered === null ? 'grab' : 'pointer';
                scene.dirty = true;
            }
        };

        const onPointerUp = (event: PointerEvent): void => {
            if (drag === null) {
                return;
            }

            const wasClick = !drag.moved;
            drag = null;
            canvas.style.cursor = scene.hovered === null ? 'grab' : 'pointer';

            if (wasClick) {
                const hit = hitTest(event);
                callbacks.current.onSelect(
                    hit === null ? null : scene.nodes[hit].id,
                );
            }
        };

        const onPointerLeave = (): void => {
            if (scene.hovered !== null) {
                scene.hovered = null;
                scene.dirty = true;
            }
        };

        const onDoubleClick = (event: MouseEvent): void => {
            const hit = hitTest(event);

            if (hit !== null) {
                callbacks.current.onOpen(scene.nodes[hit]);
            }
        };

        const onWheel = (event: WheelEvent): void => {
            event.preventDefault();
            const point = local(event);
            const from = scene.target ?? scene.camera;
            const k = Math.min(
                MAX_ZOOM,
                Math.max(MIN_ZOOM, from.k * Math.exp(-event.deltaY * 0.0015)),
            );
            const worldX = (point.x - from.x) / from.k;
            const worldY = (point.y - from.y) / from.k;
            scene.camera = {
                x: point.x - worldX * k,
                y: point.y - worldY * k,
                k,
            };
            scene.target = null;
            scene.dirty = true;
        };

        canvas.addEventListener('pointerdown', onPointerDown);
        canvas.addEventListener('pointermove', onPointerMove);
        canvas.addEventListener('pointerup', onPointerUp);
        canvas.addEventListener('pointerleave', onPointerLeave);
        canvas.addEventListener('dblclick', onDoubleClick);
        canvas.addEventListener('wheel', onWheel, { passive: false });

        return () => {
            canvas.removeEventListener('pointerdown', onPointerDown);
            canvas.removeEventListener('pointermove', onPointerMove);
            canvas.removeEventListener('pointerup', onPointerUp);
            canvas.removeEventListener('pointerleave', onPointerLeave);
            canvas.removeEventListener('dblclick', onDoubleClick);
            canvas.removeEventListener('wheel', onWheel);
        };
    }, []);

    const zoomBy = (factor: number): void => {
        const scene = sceneRef.current;
        const from = scene.target ?? scene.camera;
        const k = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, from.k * factor));
        const centreX = scene.width / 2;
        const centreY = scene.height / 2;
        moveCamera({
            x: centreX - ((centreX - from.x) / from.k) * k,
            y: centreY - ((centreY - from.y) / from.k) * k,
            k,
        });
    };

    const fit = (): void => moveCamera(fitCamera(sceneRef.current));

    const onKeyDown = (event: React.KeyboardEvent<HTMLCanvasElement>): void => {
        const scene = sceneRef.current;

        if (event.key === 'Escape') {
            onSelect(null);
        } else if (event.key === 'Enter' && scene.selected !== null) {
            onOpen(scene.nodes[scene.selected]);
        } else if (event.key === '+' || event.key === '=') {
            zoomBy(1.3);
        } else if (event.key === '-') {
            zoomBy(1 / 1.3);
        } else if (event.key === '0') {
            fit();
        } else {
            return;
        }

        event.preventDefault();
    };

    return (
        <div
            ref={containerRef}
            className="relative h-full min-h-[28rem] w-full overflow-hidden rounded-xl border bg-card"
            style={{
                backgroundImage:
                    'radial-gradient(ellipse at 50% 40%, color-mix(in oklab, var(--primary) 7%, transparent), transparent 70%)',
            }}
        >
            <canvas
                ref={canvasRef}
                role="img"
                tabIndex={0}
                aria-label={t('brain.graph.canvasLabel')}
                onKeyDown={onKeyDown}
                className="absolute inset-0 size-full cursor-grab touch-none rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            <div className="absolute right-3 bottom-3 flex flex-col gap-1">
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8 bg-card/80 backdrop-blur"
                    onClick={() => zoomBy(1.3)}
                    aria-label={t('brain.graph.zoomIn')}
                    title={t('brain.graph.zoomIn')}
                >
                    <Plus />
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8 bg-card/80 backdrop-blur"
                    onClick={() => zoomBy(1 / 1.3)}
                    aria-label={t('brain.graph.zoomOut')}
                    title={t('brain.graph.zoomOut')}
                >
                    <Minus />
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8 bg-card/80 backdrop-blur"
                    onClick={fit}
                    aria-label={t('brain.graph.fitView')}
                    title={t('brain.graph.fitView')}
                >
                    <Maximize />
                </Button>
            </div>
        </div>
    );
}
