/**
 * The whole brain as a living graph (#254, #270, #273), drawn on a canvas so hundreds of nodes stay
 * smooth. Positions come from `layoutBrainGraph` (d3-force, settled once); on top of them every
 * node floats a few pixels on its own slow loop, and a handful of beams run along the links.
 *
 * - On load the brain unfolds from its centre, nodes popping in from the middle outwards.
 * - Click selects a node: a pulse rings out, its neighbours light up, the rest fades, and beams run
 *   only along the selected node's links (at most MAX_SELECTED_BEAMS). Click empty space to clear.
 * - Hover previews a node's links. Double click (or Enter on the focused canvas) opens the item.
 * - Areas hidden from the legend fade out with their links; search matches glow, the rest fades.
 * - Drag to pan, wheel to zoom, the buttons zoom and fit (to the visible nodes).
 * - Labels only where they fit: the selection, the hovered node, matches and hubs, more as you
 *   zoom in, never overlapping.
 * - Reduced motion: no intro, float, beams, pulse or dust; fades and the camera jump; the canvas
 *   redraws only when something changes. Colours come from the theme tokens and follow light/dark.
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
    easeOutBack,
    easeOutCubic,
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
/** The intro: the last node starts popping in after INTRO_SPREAD, each takes INTRO_DURATION. */
const INTRO_SPREAD = 0.9;
const INTRO_DURATION = 0.7;
const PULSE_DURATION = 1.1;
/** Faint specks behind the graph that drift with a little parallax. */
const DUST_COUNT = 70;

type Camera = { x: number; y: number; k: number };

export type CentreRequest = { id: string; nonce: number } | null;

type Props = {
    nodes: PlacedBrainNode[];
    edges: BrainGraphEdgeInput[];
    areas: string[];
    /** Areas switched off in the legend: their nodes and links fade out. */
    hiddenAreas: string[];
    /** Search matches (ids), or null when there is no search. */
    matchIds: string[] | null;
    selectedId: string | null;
    onSelect: (id: string | null) => void;
    onOpen: (node: PlacedBrainNode) => void;
    /** Glide the camera to this node; a new nonce asks again for the same node. */
    centreRequest: CentreRequest;
    /** A new value fits the camera to the visible nodes. */
    fitRequest: number;
};

/** Everything the frame loop reads, kept in a ref so drawing never re-renders React. */
type Scene = {
    nodes: PlacedBrainNode[];
    indexById: Map<string, number>;
    links: Array<[number, number]>;
    nodeArea: number[];
    neighbours: Set<number> | null;
    matches: Set<number> | null;
    hiddenAreas: Set<number>;
    selected: number | null;
    hovered: number | null;
    /** Animated per node: shown (1) or hidden by the legend (0). */
    visible: Float32Array;
    /** Animated per node: full (1) or faded by a selection or a search. */
    emphasis: Float32Array;
    /** Per node, 0 at the centre and 1 at the rim: when it pops in during the intro. */
    introDelay: Float32Array;
    introStart: number | null;
    pulseStart: number | null;
    lastTime: number | null;
    centreX: number;
    centreY: number;
    px: Float32Array;
    py: Float32Array;
    /** Per node, the intro and legend scale of the body (0..~1.1). */
    scale: Float32Array;
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

/** A small deterministic hash, so ambient beams and dust need no state. */
function hash(value: number): number {
    const x = Math.sin(value * 12.9898) * 43758.5453;

    return x - Math.floor(x);
}

function isShown(scene: Scene, index: number): boolean {
    return !scene.hiddenAreas.has(scene.nodeArea[index]);
}

function fitCamera(scene: Scene): Camera {
    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;

    scene.nodes.forEach((node, index) => {
        if (!isShown(scene, index)) {
            return;
        }

        minX = Math.min(minX, node.x - node.radius);
        minY = Math.min(minY, node.y - node.radius);
        maxX = Math.max(maxX, node.x + node.radius);
        maxY = Math.max(maxY, node.y + node.radius);
    });

    if (!Number.isFinite(minX) || scene.width === 0) {
        return { x: scene.width / 2, y: scene.height / 2, k: 1 };
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

/** Moves the animated values towards their targets; returns whether anything is still moving. */
function stepTransitions(scene: Scene, time: number): boolean {
    const dt =
        scene.lastTime === null ? 0 : Math.min(0.1, time - scene.lastTime);
    scene.lastTime = time;
    const ease = scene.animate ? 1 - Math.exp(-dt * 9) : 1;
    let moving = false;

    for (let index = 0; index < scene.nodes.length; index++) {
        const visibleTarget = isShown(scene, index) ? 1 : 0;
        let emphasisTarget = 1;

        if (scene.neighbours !== null) {
            emphasisTarget = scene.neighbours.has(index) ? 1 : 0.14;
        } else if (scene.matches !== null) {
            emphasisTarget = scene.matches.has(index) ? 1 : 0.22;
        }

        scene.visible[index] += (visibleTarget - scene.visible[index]) * ease;
        scene.emphasis[index] +=
            (emphasisTarget - scene.emphasis[index]) * ease;

        if (
            Math.abs(visibleTarget - scene.visible[index]) > 0.005 ||
            Math.abs(emphasisTarget - scene.emphasis[index]) > 0.005
        ) {
            moving = true;
        } else {
            scene.visible[index] = visibleTarget;
            scene.emphasis[index] = emphasisTarget;
        }
    }

    return moving;
}

/** Faint specks in screen space that drift with a fraction of the camera, for depth. */
function drawDust(
    context: CanvasRenderingContext2D,
    scene: Scene,
    palette: Palette,
    time: number,
): void {
    for (let speck = 0; speck < DUST_COUNT; speck++) {
        const depth = 0.04 + hash(speck * 3.1) * 0.12;
        const span = Math.max(scene.width, scene.height) + 200;
        const x =
            ((((hash(speck * 1.7) * span + scene.camera.x * depth) % span) +
                span) %
                span) -
            100;
        const y =
            ((((hash(speck * 2.3) * span + scene.camera.y * depth) % span) +
                span) %
                span) -
            100;
        const twinkle =
            0.5 + 0.5 * Math.sin(time * (0.4 + hash(speck) * 0.8) + speck);

        context.beginPath();
        context.arc(x, y, 0.6 + hash(speck * 5.3) * 1.1, 0, Math.PI * 2);
        context.fillStyle = rgba(
            palette.mutedForeground,
            0.05 + twinkle * 0.12,
        );
        context.fill();
    }
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

    const { camera, nodes, links, px, py, visible, emphasis, scale } = scene;
    const hasSelection = scene.selected !== null;

    context.setTransform(scene.dpr, 0, 0, scene.dpr, 0, 0);
    context.clearRect(0, 0, scene.width, scene.height);

    if (scene.animate) {
        drawDust(context, scene, palette, time);
    }

    // Positions and scales: the float, the intro unfolding from the centre, the legend fade.
    for (let index = 0; index < nodes.length; index++) {
        const offset = scene.animate
            ? floatOffset(index, time, FLOAT_AMPLITUDE)
            : { dx: 0, dy: 0 };
        let spread = 1;
        let pop = 1;

        if (scene.animate && scene.introStart !== null) {
            const progress =
                (time -
                    scene.introStart -
                    scene.introDelay[index] * INTRO_SPREAD) /
                INTRO_DURATION;
            spread = easeOutCubic(progress);
            pop = progress <= 0 ? 0 : easeOutBack(progress);
        }

        px[index] =
            scene.centreX +
            (nodes[index].x - scene.centreX) * spread +
            offset.dx;
        py[index] =
            scene.centreY +
            (nodes[index].y - scene.centreY) * spread +
            offset.dy;
        scale[index] = pop * (0.4 + 0.6 * visible[index]);
    }

    context.save();
    context.translate(camera.x, camera.y);
    context.scale(camera.k, camera.k);
    context.lineCap = 'round';

    // Quiet links, bucketed by strength so each bucket is one path.
    const isLit = (from: number, to: number): boolean =>
        hasSelection && (from === scene.selected || to === scene.selected);
    const isPreviewed = (from: number, to: number): boolean =>
        !hasSelection &&
        scene.hovered !== null &&
        (from === scene.hovered || to === scene.hovered);
    const buckets = new Map<number, Array<[number, number]>>();
    const litLinks: Array<[number, number]> = [];
    const previewLinks: Array<[number, number]> = [];

    for (const link of links) {
        const [from, to] = link;
        const presence =
            Math.min(visible[from], visible[to]) *
            Math.min(1, scale[from], scale[to]);

        if (presence < 0.02) {
            continue;
        }

        if (isLit(from, to)) {
            litLinks.push(link);
            continue;
        }

        if (isPreviewed(from, to)) {
            previewLinks.push(link);
            continue;
        }

        const strength =
            Math.round(presence * Math.min(emphasis[from], emphasis[to]) * 10) /
            10;

        if (strength > 0) {
            const bucket = buckets.get(strength) ?? [];
            bucket.push(link);
            buckets.set(strength, bucket);
        }
    }

    context.lineWidth = 1 / camera.k;

    for (const [strength, bucket] of buckets) {
        context.beginPath();

        for (const [from, to] of bucket) {
            context.moveTo(px[from], py[from]);
            context.lineTo(px[to], py[to]);
        }

        context.strokeStyle = rgba(
            palette.border,
            0.8 * Math.max(0.15, strength),
        );
        context.stroke();
    }

    const strokeLinks = (
        group: Array<[number, number]>,
        colour: string,
        width: number,
    ): void => {
        if (group.length === 0) {
            return;
        }

        context.beginPath();

        for (const [from, to] of group) {
            context.moveTo(px[from], py[from]);
            context.lineTo(px[to], py[to]);
        }

        context.lineWidth = width / camera.k;
        context.strokeStyle = colour;
        context.stroke();
    };

    strokeLinks(litLinks, rgba(palette.primary, 0.5), 1.5);

    if (scene.hovered !== null) {
        strokeLinks(
            previewLinks,
            rgba(palette.areas[scene.nodeArea[scene.hovered]], 0.6),
            1.4,
        );
    }

    if (scene.animate) {
        drawBeams(context, scene, palette, litLinks, time);
    }

    // The pulse rings out of a freshly selected node.
    if (scene.animate && scene.selected !== null && scene.pulseStart !== null) {
        const radius = nodes[scene.selected].radius;

        for (let ring = 0; ring < 2; ring++) {
            const progress =
                (time - scene.pulseStart - ring * 0.22) / PULSE_DURATION;

            if (progress > 0 && progress < 1) {
                context.beginPath();
                context.arc(
                    px[scene.selected],
                    py[scene.selected],
                    radius + easeOutCubic(progress) * (radius * 2.5 + 40),
                    0,
                    Math.PI * 2,
                );
                context.lineWidth = 2 / camera.k;
                context.strokeStyle = rgba(
                    palette.primary,
                    (1 - progress) * 0.55,
                );
                context.stroke();
            }
        }
    }

    // Nodes: a soft halo for the ones that matter, then the body.
    for (let index = 0; index < nodes.length; index++) {
        if (scale[index] <= 0.01 || visible[index] <= 0.01) {
            continue;
        }

        const node = nodes[index];
        const colour = palette.areas[scene.nodeArea[index]];
        const radius = node.radius * scale[index];
        const alpha = emphasis[index] * visible[index];
        const isNeighbour = scene.neighbours?.has(index) ?? false;
        const isMatch = scene.matches?.has(index) ?? false;
        const isFocus = index === scene.selected || index === scene.hovered;
        const haloStrength = isFocus
            ? 0.3
            : isNeighbour || isMatch
              ? 0.24
              : !hasSelection && node.degree >= LABELLED_DEGREE
                ? 0.13
                : 0;

        if (haloStrength > 0 && alpha > 0.3) {
            const reach = radius * (isMatch && !isFocus ? 2.9 : 2.4);
            const halo = context.createRadialGradient(
                px[index],
                py[index],
                radius * 0.6,
                px[index],
                py[index],
                reach,
            );
            halo.addColorStop(0, rgba(colour, haloStrength * alpha));
            halo.addColorStop(1, rgba(colour, 0));
            context.fillStyle = halo;
            context.beginPath();
            context.arc(px[index], py[index], reach, 0, Math.PI * 2);
            context.fill();
        }

        context.beginPath();
        context.arc(px[index], py[index], radius, 0, Math.PI * 2);
        context.fillStyle = rgba(colour, 0.92 * alpha);
        context.fill();
        context.lineWidth = 1.5 / camera.k;
        context.strokeStyle = rgba(palette.card, alpha);
        context.stroke();

        if (isFocus || (isMatch && !hasSelection)) {
            context.beginPath();
            context.arc(
                px[index],
                py[index],
                radius + 4 / camera.k,
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
 * selected node along its links; without one, a few drift across random visible links to show the
 * graph is alive. Never more than MAX_SELECTED_BEAMS at once.
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
        litLinks
            .filter(([from, to]) => isShown(scene, from) && isShown(scene, to))
            .slice(0, MAX_SELECTED_BEAMS)
            .forEach(([from, to], index) => {
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

            if (
                scene.visible[link[0]] < 0.9 ||
                scene.visible[link[1]] < 0.9 ||
                scene.emphasis[link[0]] < 0.9 ||
                scene.emphasis[link[1]] < 0.9
            ) {
                continue;
            }

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
        if (
            index !== null &&
            !seen.has(index) &&
            scene.visible[index] > 0.6 &&
            scene.scale[index] > 0.8
        ) {
            seen.add(index);
            candidates.push(index);
        }
    };
    const byDegree = (a: number, b: number): number =>
        nodes[b].degree - nodes[a].degree;

    add(scene.selected);
    add(scene.hovered);
    [...(scene.neighbours ?? [])].sort(byDegree).forEach(add);
    [...(scene.matches ?? [])].sort(byDegree).slice(0, 60).forEach(add);

    const showsAll = camera.k >= ALL_LABELS_ZOOM;
    const quiet = scene.selected === null && scene.matches === null;
    nodes
        .map((_, index) => index)
        .filter(
            (index) =>
                showsAll || (quiet && nodes[index].degree >= LABELLED_DEGREE),
        )
        .sort(byDegree)
        .slice(0, 300)
        .forEach(add);

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
        const top =
            (py[index] + nodes[index].radius * scene.scale[index]) * camera.k +
            camera.y +
            6;
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
        const alpha = Math.max(0.35, scene.emphasis[index]);

        context.beginPath();
        context.roundRect(box.x, box.y, width, height, height / 2);
        context.fillStyle = rgba(palette.card, 0.88 * alpha);
        context.fill();
        context.lineWidth = 1;
        context.strokeStyle = rgba(
            isFocus ? palette.primary : palette.border,
            (isFocus ? 0.6 : 0.9) * alpha,
        );
        context.stroke();
        context.fillStyle = rgba(
            isFocus ? palette.foreground : palette.mutedForeground,
            alpha,
        );
        context.fillText(label, centreX, top + height / 2 + 0.5);
    }
}

export function BrainGraphCanvas({
    nodes,
    edges,
    areas,
    hiddenAreas,
    matchIds,
    selectedId,
    onSelect,
    onOpen,
    centreRequest,
    fitRequest,
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
        matches: null,
        hiddenAreas: new Set(),
        selected: null,
        hovered: null,
        visible: new Float32Array(0),
        emphasis: new Float32Array(0),
        introDelay: new Float32Array(0),
        introStart: null,
        pulseStart: null,
        lastTime: null,
        centreX: 0,
        centreY: 0,
        px: new Float32Array(0),
        py: new Float32Array(0),
        scale: new Float32Array(0),
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

    // Data: index the nodes, resolve links to indexes, colour by area, order the intro.
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
        scene.scale = new Float32Array(nodes.length).fill(1);
        scene.visible = new Float32Array(nodes.length).fill(1);
        scene.emphasis = new Float32Array(nodes.length).fill(1);

        const count = Math.max(1, nodes.length);
        scene.centreX = nodes.reduce((sum, node) => sum + node.x, 0) / count;
        scene.centreY = nodes.reduce((sum, node) => sum + node.y, 0) / count;
        const distances = nodes.map((node) =>
            Math.hypot(node.x - scene.centreX, node.y - scene.centreY),
        );
        const farthest = Math.max(1, ...distances);
        scene.introDelay = Float32Array.from(
            distances.map((distance) => distance / farthest),
        );

        scene.labelWidths = new Map();
        scene.introStart = null;
        scene.lastTime = null;
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
            scene.pulseStart = null;
        } else {
            scene.selected = index;
            scene.neighbours = new Set([index]);
            scene.pulseStart = performance.now() / 1000;

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
        scene.hiddenAreas = new Set(
            hiddenAreas.map((area) => areaIndex(area, areas)),
        );
        scene.dirty = true;
    }, [hiddenAreas, areas, nodes]);

    useEffect(() => {
        const scene = sceneRef.current;
        scene.matches =
            matchIds === null
                ? null
                : new Set(
                      matchIds.flatMap((id) => {
                          const index = scene.indexById.get(id);

                          return index === undefined ? [] : [index];
                      }),
                  );
        scene.dirty = true;
    }, [matchIds, nodes]);

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

    useEffect(() => {
        const scene = sceneRef.current;

        if (fitRequest > 0 && scene.fitted) {
            moveCamera(fitCamera(scene));
        }
    }, [fitRequest, moveCamera]);

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
            const time = now / 1000;

            if (!scene.fitted && scene.width > 0 && scene.nodes.length > 0) {
                scene.camera = fitCamera(scene);
                scene.fitted = true;
                scene.introStart = time;

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

            if (stepTransitions(scene, time)) {
                scene.dirty = true;
            }

            if (scene.animate || scene.dirty) {
                draw(context, scene, time);
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
                if (!isShown(scene, index)) {
                    continue;
                }

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
