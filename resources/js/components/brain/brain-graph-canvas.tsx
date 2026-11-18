/**
 * The whole brain as a living graph (#254, #270, #273, #275), drawn on a canvas so hundreds of
 * nodes stay smooth. Positions come from `layoutBrainGraph` (d3-force, settled once); on top of
 * them every node floats a few pixels on its own slow loop, and a handful of beams run along links.
 *
 * - On load the brain unfolds from its centre, nodes popping in from the middle outwards.
 * - Click selects a node: a pulse rings out, its neighbours light up, the rest fades, and beams run
 *   only along the selected node's links (at most MAX_SELECTED_BEAMS). Click empty space to clear.
 * - Shift-click another node traces the shortest path (the page computes it): the chain lights up
 *   and beams cascade along it.
 * - Drag a node to move it: its neighbours follow on springs, and it stays where it is dropped
 *   (Reset layout lets everything spring back). Drag empty space to pan; wheel zooms.
 * - A new layout (constellations by area) glides every node to its new place.
 * - The minimap (bottom left) shows the whole brain and the view; click or drag it to move.
 * - Hover previews a node's links. Double click (or Enter on the focused canvas) opens the item.
 * - Areas hidden from the legend fade out with their links; search matches glow, the rest fades.
 * - Labels only where they fit, in priority order, never overlapping.
 * - Reduced motion: no intro, float, beams, pulse, dust or glides; the canvas redraws only when
 *   something changes. Colours come from the theme tokens and follow light/dark.
 */
import { Download, Maximize, Minus, Plus, Undo2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type * as React from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { index as explorerIndex } from '@/routes/explorer';
import type {
    BrainGraphEdgeInput,
    PlacedBrainNode,
} from '@/lib/brain-force-layout';
import {
    createSpringState,
    type SpringState,
    stepSprings,
} from '@/lib/brain-graph-physics';
import {
    areaIndex,
    boxesOverlap,
    easeOutBack,
    easeOutCubic,
    floatOffset,
    freshness,
    mixRgb,
    type Rgb,
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
const MIN_ZOOM = 0.05;
const MAX_ZOOM = 4;
const LABEL_FONT_SIZE = 12;
/** The intro: the last node starts popping in after INTRO_SPREAD, each takes INTRO_DURATION. */
const INTRO_SPREAD = 0.9;
const INTRO_DURATION = 0.7;
const PULSE_DURATION = 1.1;
const LAYOUT_GLIDE = 1.1;
/** Faint specks behind the graph that drift with a little parallax. */
const DUST_COUNT = 70;
/** The width the side card (w-80 plus its margin) takes from the left of the canvas. */
const SIDE_CARD_SPACE = 340;
const MINIMAP_WIDTH = 176;
const MINIMAP_HEIGHT = 116;
const MINIMAP_MARGIN = 12;
/** Below this canvas width the minimap would crowd the graph. */
const MINIMAP_MIN_CANVAS = 640;

type Camera = { x: number; y: number; k: number };

export type CentreRequest = { id: string; nonce: number } | null;

export type GraphColourBy = 'area' | 'freshness';

type Props = {
    nodes: PlacedBrainNode[];
    edges: BrainGraphEdgeInput[];
    areas: string[];
    /** Areas switched off in the legend: their nodes and links fade out. */
    hiddenAreas: string[];
    /** Search matches (ids), or null when there is no search. */
    matchIds: string[] | null;
    /** Items the Review filter hides (ids), or null when it is off (#287). */
    hiddenIds: string[] | null;
    /** What a node's colour says: its area, or how fresh its content is (#287). */
    colourBy: GraphColourBy;
    /** A traced path (ids in order), or null. */
    pathIds: string[] | null;
    /** Area names to write over each constellation, in `areas` order; null to write none. */
    areaLabels: string[] | null;
    selectedId: string | null;
    onSelect: (id: string | null) => void;
    /** Shift-click on a node: the page traces a path from the selection. */
    onTrace: (id: string) => void;
    onOpen: (node: PlacedBrainNode) => void;
    /** Glide the camera to this node; a new nonce asks again for the same node. */
    centreRequest: CentreRequest;
    /** A new value fits the camera to the visible nodes. */
    fitRequest: number;
};

type Glide = {
    fromX: Float32Array;
    fromY: Float32Array;
    toX: Float32Array;
    toY: Float32Array;
    start: number | null;
};

type Minimap = {
    x: number;
    y: number;
    width: number;
    height: number;
    scale: number;
    worldX: number;
    worldY: number;
};

/** Everything the frame loop reads, kept in a ref so drawing never re-renders React. */
type Scene = {
    nodes: PlacedBrainNode[];
    indexById: Map<string, number>;
    links: Array<[number, number]>;
    nodeArea: number[];
    neighbours: Set<number> | null;
    matches: Set<number> | null;
    path: number[] | null;
    pathSet: Set<number> | null;
    areaLabels: string[] | null;
    hiddenAreas: Set<number>;
    /** 1 for nodes the Review filter hides (#287). */
    hiddenNodes: Uint8Array;
    /** Per node, 1 when something waits for review on it (#287). */
    signals: Uint8Array;
    /** Per node, how fresh its content is, 0..1 (#287). */
    fresh: Float32Array;
    colourBy: GraphColourBy;
    selected: number | null;
    hovered: number | null;
    /** Laid out place of each node (moves during a layout glide). */
    bx: Float32Array;
    by: Float32Array;
    springs: SpringState;
    springsActive: boolean;
    dragged: number | null;
    glide: Glide | null;
    /** Animated per node: shown (1) or hidden by the legend (0). */
    visible: Float32Array;
    /** Animated per node: full (1) or faded by a selection, path or search. */
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
    minimap: Minimap | null;
    width: number;
    height: number;
    dpr: number;
    palette: Palette | null;
    animate: boolean;
    dirty: boolean;
    fitted: boolean;
    labelWidths: Map<number, number>;
    /** Node indexes, most linked first: the label order, sorted once per data change. */
    byDegree: number[];
    /** Bumped whenever laid out places move (data, glide, springs), so the hit grid rebuilds. */
    positionsVersion: number;
    hitGrid: HitGrid | null;
};

type HitGrid = { version: number; cells: Map<number, number[]> };

/** Cell size of the hit grid, in world units: a little more than the biggest node. */
const HIT_CELL = 64;

function hitCellKey(column: number, row: number): number {
    return column * 100_003 + row;
}

function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/** A small deterministic hash, so ambient beams and dust need no state. */
function hash(value: number): number {
    const wave = Math.sin(value * 12.9898) * 43758.5453;

    return wave - Math.floor(wave);
}

function isShown(scene: Scene, index: number): boolean {
    return (
        !scene.hiddenAreas.has(scene.nodeArea[index]) &&
        scene.hiddenNodes[index] !== 1
    );
}

/** Freshness is drawn in this many steps, so nodes still batch by colour. */
const FRESH_STEPS = 8;
/** Changed within about a week: glows in the freshness view. */
const FRESH_GLOW = 0.68;

/** The colour key of a node: its area, or its freshness step (offset past the areas). */
function colourKey(scene: Scene, index: number): number {
    return scene.colourBy === 'area'
        ? scene.nodeArea[index]
        : 10 + Math.round(scene.fresh[index] * FRESH_STEPS);
}

function keyColour(palette: Palette, key: number): Rgb {
    return key < 10
        ? palette.areas[key]
        : mixRgb(
              palette.mutedForeground,
              palette.primary,
              (key - 10) / FRESH_STEPS,
          );
}

function nodeColour(scene: Scene, palette: Palette, index: number): Rgb {
    return keyColour(palette, colourKey(scene, index));
}

/**
 * The camera that shows every visible node at `xs`/`ys` (the laid out places by default), or only
 * the traced path when there is one.
 */
function fitCamera(
    scene: Scene,
    xs: Float32Array = scene.bx,
    ys: Float32Array = scene.by,
): Camera {
    const only =
        scene.pathSet !== null && scene.pathSet.size > 1 ? scene.pathSet : null;

    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;

    scene.nodes.forEach((node, index) => {
        if (!isShown(scene, index) || (only !== null && !only.has(index))) {
            return;
        }

        const nodeX = xs[index] + scene.springs.ox[index];
        const nodeY = ys[index] + scene.springs.oy[index];
        minX = Math.min(minX, nodeX - node.radius);
        minY = Math.min(minY, nodeY - node.radius);
        maxX = Math.max(maxX, nodeX + node.radius);
        maxY = Math.max(maxY, nodeY + node.radius);
    });

    if (!Number.isFinite(minX) || scene.width === 0) {
        return { x: scene.width / 2, y: scene.height / 2, k: 1 };
    }

    const padding = 56;
    // With the side card open on a wide canvas, frame the nodes in the space to its right.
    const cardSpace =
        scene.width > 720 && (scene.selected !== null || only !== null)
            ? SIDE_CARD_SPACE
            : 0;
    const width = scene.width - cardSpace;
    const k = Math.min(
        1.4,
        Math.max(
            MIN_ZOOM,
            Math.min(
                (width - padding * 2) / Math.max(1, maxX - minX),
                (scene.height - padding * 2) / Math.max(1, maxY - minY),
            ),
        ),
    );

    return {
        x: cardSpace + width / 2 - ((minX + maxX) / 2) * k,
        y: scene.height / 2 - ((minY + maxY) / 2) * k,
        k,
    };
}

function cameraOn(scene: Scene, index: number): Camera {
    const k = Math.max(scene.camera.k, 1.2);
    // The side card covers the top left: keep the node a little right of centre on wide canvases.
    const centreX = scene.width > 720 ? scene.width / 2 + 140 : scene.width / 2;
    const nodeX = scene.bx[index] + scene.springs.ox[index];
    const nodeY = scene.by[index] + scene.springs.oy[index];

    return { x: centreX - nodeX * k, y: scene.height / 2 - nodeY * k, k };
}

function linkKey(from: number, to: number): number {
    return from < to ? from * 1_000_003 + to : to * 1_000_003 + from;
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

        if (scene.pathSet !== null) {
            emphasisTarget = scene.pathSet.has(index) ? 1 : 0.1;
        } else if (scene.neighbours !== null) {
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

    // A new layout: glide the laid out places from the old to the new.
    if (scene.glide !== null) {
        const glide = scene.glide;
        glide.start ??= time;
        const progress = scene.animate
            ? easeOutCubic((time - glide.start) / LAYOUT_GLIDE)
            : 1;

        for (let index = 0; index < scene.nodes.length; index++) {
            scene.bx[index] =
                glide.fromX[index] +
                (glide.toX[index] - glide.fromX[index]) * progress;
            scene.by[index] =
                glide.fromY[index] +
                (glide.toY[index] - glide.fromY[index]) * progress;
        }

        if (progress >= 1) {
            scene.glide = null;
        }

        scene.positionsVersion += 1;
        moving = true;
    }

    if (scene.springsActive) {
        const fastest = stepSprings(scene.springs, scene.links, dt);

        if (fastest < 0.05 && scene.dragged === null) {
            scene.springsActive = false;
        }

        scene.positionsVersion += 1;
        moving = true;
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
        const speckX =
            ((((hash(speck * 1.7) * span + scene.camera.x * depth) % span) +
                span) %
                span) -
            100;
        const speckY =
            ((((hash(speck * 2.3) * span + scene.camera.y * depth) % span) +
                span) %
                span) -
            100;
        const twinkle =
            0.5 + 0.5 * Math.sin(time * (0.4 + hash(speck) * 0.8) + speck);

        context.beginPath();
        context.arc(
            speckX,
            speckY,
            0.6 + hash(speck * 5.3) * 1.1,
            0,
            Math.PI * 2,
        );
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
    const { ox, oy, pinned } = scene.springs;
    const hasSelection = scene.selected !== null;
    const hasPath = scene.path !== null && scene.path.length > 1;

    context.setTransform(scene.dpr, 0, 0, scene.dpr, 0, 0);
    context.clearRect(0, 0, scene.width, scene.height);

    if (scene.animate) {
        drawDust(context, scene, palette, time);
    }

    // Positions and scales: the float, the intro unfolding from the centre, springs, the legend fade.
    for (let index = 0; index < nodes.length; index++) {
        const floats =
            scene.animate && pinned[index] === 0 && index !== scene.dragged;
        const offset = floats
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
            (scene.bx[index] + ox[index] - scene.centreX) * spread +
            offset.dx;
        py[index] =
            scene.centreY +
            (scene.by[index] + oy[index] - scene.centreY) * spread +
            offset.dy;
        scale[index] = pop * (0.4 + 0.6 * visible[index]);
    }

    context.save();
    context.translate(camera.x, camera.y);
    context.scale(camera.k, camera.k);
    context.lineCap = 'round';

    const pathLinks = new Set<number>();

    if (hasPath && scene.path !== null) {
        for (let step = 0; step + 1 < scene.path.length; step++) {
            pathLinks.add(linkKey(scene.path[step], scene.path[step + 1]));
        }
    }

    // Quiet links, bucketed by strength so each bucket is one path.
    const isLit = (from: number, to: number): boolean =>
        !hasPath &&
        hasSelection &&
        (from === scene.selected || to === scene.selected);
    const isPreviewed = (from: number, to: number): boolean =>
        !hasSelection &&
        !hasPath &&
        scene.hovered !== null &&
        (from === scene.hovered || to === scene.hovered);
    // What the view shows, in world units, with a margin for halos and labels.
    const margin = 60 / camera.k;
    const viewLeft = -camera.x / camera.k - margin;
    const viewTop = -camera.y / camera.k - margin;
    const viewRight = (scene.width - camera.x) / camera.k + margin;
    const viewBottom = (scene.height - camera.y) / camera.k + margin;
    const inView = (index: number): boolean =>
        px[index] >= viewLeft &&
        px[index] <= viewRight &&
        py[index] >= viewTop &&
        py[index] <= viewBottom;
    const linkInView = (from: number, to: number): boolean =>
        Math.max(px[from], px[to]) >= viewLeft &&
        Math.min(px[from], px[to]) <= viewRight &&
        Math.max(py[from], py[to]) >= viewTop &&
        Math.min(py[from], py[to]) <= viewBottom;

    const buckets = new Map<number, Array<[number, number]>>();
    const litLinks: Array<[number, number]> = [];
    const previewLinks: Array<[number, number]> = [];
    const tracedLinks: Array<[number, number]> = [];

    for (const link of links) {
        const [from, to] = link;
        const presence =
            Math.min(visible[from], visible[to]) *
            Math.min(1, scale[from], scale[to]);

        if (presence < 0.02 || !linkInView(from, to)) {
            continue;
        }

        if (hasPath && pathLinks.has(linkKey(from, to))) {
            tracedLinks.push(link);
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
    strokeLinks(tracedLinks, rgba(palette.primary, 0.75), 2.5);

    if (scene.hovered !== null) {
        strokeLinks(
            previewLinks,
            rgba(nodeColour(scene, palette, scene.hovered), 0.6),
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

    const pathEnds = new Set<number>(
        hasPath && scene.path !== null
            ? [scene.path[0], scene.path[scene.path.length - 1]]
            : [],
    );

    // Plain nodes (no halo, no ring) are batched into one path per colour and strength, so a big
    // brain costs a few fills instead of thousands; the ones that matter are drawn after, on top.
    const plain = new Map<number, number[]>();
    const special: number[] = [];

    for (let index = 0; index < nodes.length; index++) {
        if (scale[index] <= 0.01 || visible[index] <= 0.01 || !inView(index)) {
            continue;
        }

        const isSpecial =
            index === scene.selected ||
            index === scene.hovered ||
            index === scene.dragged ||
            pinned[index] === 1 ||
            pathEnds.has(index) ||
            (scene.neighbours?.has(index) ?? false) ||
            (scene.matches?.has(index) ?? false) ||
            (scene.pathSet?.has(index) ?? false) ||
            (!hasSelection && nodes[index].degree >= LABELLED_DEGREE) ||
            (scene.colourBy === 'freshness' &&
                scene.fresh[index] >= FRESH_GLOW);

        if (isSpecial) {
            special.push(index);
            continue;
        }

        const strength = Math.round(emphasis[index] * visible[index] * 10);
        const key = colourKey(scene, index) * 100 + strength;
        const batch = plain.get(key) ?? [];
        batch.push(index);
        plain.set(key, batch);
    }

    // Below ~3px on screen the card outline would only blur the dot.
    const outlines = camera.k * 6 >= 3;

    for (const [key, batch] of plain) {
        const colour = keyColour(palette, Math.floor(key / 100));
        const alpha = (key % 100) / 10;
        context.beginPath();

        for (const index of batch) {
            const radius = nodes[index].radius * scale[index];
            context.moveTo(px[index] + radius, py[index]);
            context.arc(px[index], py[index], radius, 0, Math.PI * 2);
        }

        context.fillStyle = rgba(colour, 0.92 * alpha);
        context.fill();

        if (outlines) {
            context.lineWidth = 1.5 / camera.k;
            context.strokeStyle = rgba(palette.card, alpha);
            context.stroke();
        }
    }

    // The nodes that matter: a soft halo, the body, a ring.
    for (const index of special) {
        const node = nodes[index];
        const colour = nodeColour(scene, palette, index);
        const radius = node.radius * scale[index];
        const alpha = emphasis[index] * visible[index];
        const isNeighbour = scene.neighbours?.has(index) ?? false;
        const isMatch = scene.matches?.has(index) ?? false;
        const isOnPath = scene.pathSet?.has(index) ?? false;
        const isFocus =
            index === scene.selected ||
            index === scene.hovered ||
            index === scene.dragged ||
            pathEnds.has(index);
        const isFreshGlow =
            scene.colourBy === 'freshness' && scene.fresh[index] >= FRESH_GLOW;
        const haloStrength = isFocus
            ? 0.3
            : isOnPath || isNeighbour || isMatch
              ? 0.24
              : isFreshGlow
                ? // Recent changes breathe in the freshness view.
                  0.22 + (scene.animate ? 0.1 * Math.sin(time * 2 + index) : 0)
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

        if (isFocus || (isMatch && !hasSelection) || pinned[index] === 1) {
            context.beginPath();
            context.arc(
                px[index],
                py[index],
                radius + 4 / camera.k,
                0,
                Math.PI * 2,
            );
            context.lineWidth = 2 / camera.k;
            const isStrong = index === scene.selected || pathEnds.has(index);
            context.strokeStyle = rgba(
                isStrong ? palette.primary : colour,
                (isStrong ? 0.9 : 0.6) * alpha,
            );
            // A dropped node wears a dashed ring: it stays where it was put.
            context.setLineDash(
                pinned[index] === 1 && !isFocus
                    ? [3 / camera.k, 3 / camera.k]
                    : [],
            );
            context.stroke();
            context.setLineDash([]);
        }
    }

    // Something waits for review: a small warning dot on the node's rim (#287).
    for (let index = 0; index < nodes.length; index++) {
        if (
            scene.signals[index] !== 1 ||
            scale[index] < 0.5 ||
            visible[index] < 0.05 ||
            !inView(index)
        ) {
            continue;
        }

        const radius = nodes[index].radius * scale[index];
        const dot = Math.max(2.5 / camera.k, radius * 0.32);
        const badgeX = px[index] + radius * Math.SQRT1_2;
        const badgeY = py[index] - radius * Math.SQRT1_2;
        const alpha = Math.max(0.35, emphasis[index]) * visible[index];
        context.beginPath();
        context.arc(badgeX, badgeY, dot, 0, Math.PI * 2);
        context.fillStyle = rgba(palette.warning, alpha);
        context.fill();
        context.lineWidth = 1.5 / camera.k;
        context.strokeStyle = rgba(palette.card, alpha);
        context.stroke();
    }

    context.restore();
    drawAreaLabels(context, scene, palette);
    drawLabels(context, scene, palette);
    drawMinimap(context, scene, palette);
}

/**
 * Beams: a short glowing trail running along a link. Along a traced path they cascade from start
 * to end; with a selection they run outward from the selected node along its links; otherwise a few
 * drift across random visible links. Never more than MAX_SELECTED_BEAMS at once.
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

    if (scene.path !== null && scene.path.length > 1) {
        const path = scene.path;

        for (let step = 0; step + 1 < path.length; step++) {
            beams.push({
                from: path[step],
                to: path[step + 1],
                progress: (((time * 0.7 - step * 0.3) % 1) + 1) % 1,
                colour: palette.primary,
                alpha: 1,
            });
        }
    } else if (scene.selected !== null) {
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
                colour: nodeColour(scene, palette, link[0]),
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

/** In constellations, each area's name floats above its cluster. */
function drawAreaLabels(
    context: CanvasRenderingContext2D,
    scene: Scene,
    palette: Palette,
): void {
    if (scene.areaLabels === null) {
        return;
    }

    const { camera, px, py, nodes } = scene;
    const sums = new Map<
        number,
        { x: number; top: number; count: number; visible: number }
    >();

    for (let index = 0; index < nodes.length; index++) {
        const area = scene.nodeArea[index];
        const entry = sums.get(area) ?? {
            x: 0,
            top: Infinity,
            count: 0,
            visible: 0,
        };
        entry.x += px[index];
        entry.top = Math.min(entry.top, py[index] - nodes[index].radius);
        entry.count += 1;
        entry.visible = Math.max(entry.visible, scene.visible[index]);
        sums.set(area, entry);
    }

    context.font = `600 13px ${palette.fontFamily}`;
    context.textAlign = 'center';
    context.textBaseline = 'bottom';

    for (const [area, entry] of sums) {
        const label = scene.areaLabels[area];

        if (label === undefined || entry.visible < 0.05) {
            continue;
        }

        const labelX = (entry.x / entry.count) * camera.k + camera.x;
        const labelY = entry.top * camera.k + camera.y - 14;
        context.fillStyle = rgba(palette.areas[area], 0.85 * entry.visible);
        context.fillText(label.toUpperCase(), labelX, labelY);
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
    const byDegree = (first: number, second: number): number =>
        nodes[second].degree - nodes[first].degree;

    add(scene.selected);
    add(scene.dragged);
    add(scene.hovered);
    (scene.path ?? []).forEach(add);
    [...(scene.neighbours ?? [])].sort(byDegree).forEach(add);
    [...(scene.matches ?? [])].sort(byDegree).slice(0, 60).forEach(add);

    const showsAll = camera.k >= ALL_LABELS_ZOOM;
    const quiet =
        scene.selected === null &&
        scene.matches === null &&
        scene.path === null;
    let listed = 0;

    for (const index of scene.byDegree) {
        if (listed >= 300) {
            break;
        }

        if (!showsAll && !(quiet && nodes[index].degree >= LABELLED_DEGREE)) {
            break; // sorted by degree: the rest are below the hub threshold too
        }

        if (
            px[index] * camera.k + camera.x < -100 ||
            px[index] * camera.k + camera.x > scene.width + 100 ||
            py[index] * camera.k + camera.y < -40 ||
            py[index] * camera.k + camera.y > scene.height + 40
        ) {
            continue;
        }

        add(index);
        listed += 1;
    }

    context.font = `500 ${LABEL_FONT_SIZE}px ${palette.fontFamily}`;
    context.textBaseline = 'middle';
    context.textAlign = 'center';

    const placed: LabelBox[] = [];
    const height = LABEL_FONT_SIZE + 8;

    if (scene.minimap !== null) {
        placed.push(scene.minimap);
    }

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
        const isFocus =
            index === scene.selected ||
            index === scene.hovered ||
            index === scene.dragged;
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

/** The whole brain in small, with the current view as a frame. */
function drawMinimap(
    context: CanvasRenderingContext2D,
    scene: Scene,
    palette: Palette,
): void {
    if (scene.width < MINIMAP_MIN_CANVAS || scene.nodes.length === 0) {
        scene.minimap = null;

        return;
    }

    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;

    for (let index = 0; index < scene.nodes.length; index++) {
        if (scene.visible[index] < 0.05) {
            continue;
        }

        const radius = scene.nodes[index].radius;
        minX = Math.min(
            minX,
            scene.bx[index] + scene.springs.ox[index] - radius,
        );
        minY = Math.min(
            minY,
            scene.by[index] + scene.springs.oy[index] - radius,
        );
        maxX = Math.max(
            maxX,
            scene.bx[index] + scene.springs.ox[index] + radius,
        );
        maxY = Math.max(
            maxY,
            scene.by[index] + scene.springs.oy[index] + radius,
        );
    }

    if (!Number.isFinite(minX)) {
        scene.minimap = null;

        return;
    }

    const inset = 10;
    const scale = Math.min(
        (MINIMAP_WIDTH - inset * 2) / Math.max(1, maxX - minX),
        (MINIMAP_HEIGHT - inset * 2) / Math.max(1, maxY - minY),
    );
    const boxX = MINIMAP_MARGIN;
    const boxY = scene.height - MINIMAP_MARGIN - MINIMAP_HEIGHT;
    // Centre the drawing inside the box.
    const worldX = minX - (MINIMAP_WIDTH / scale - (maxX - minX)) / 2;
    const worldY = minY - (MINIMAP_HEIGHT / scale - (maxY - minY)) / 2;
    scene.minimap = {
        x: boxX,
        y: boxY,
        width: MINIMAP_WIDTH,
        height: MINIMAP_HEIGHT,
        scale,
        worldX,
        worldY,
    };

    context.save();
    context.beginPath();
    context.roundRect(boxX, boxY, MINIMAP_WIDTH, MINIMAP_HEIGHT, 10);
    context.fillStyle = rgba(palette.card, 0.85);
    context.fill();
    context.lineWidth = 1;
    context.strokeStyle = rgba(palette.border, 1);
    context.stroke();
    context.clip();

    for (let index = 0; index < scene.nodes.length; index++) {
        const alpha =
            scene.visible[index] * Math.max(0.25, scene.emphasis[index]);

        if (alpha < 0.05) {
            continue;
        }

        context.beginPath();
        context.arc(
            boxX + (scene.bx[index] + scene.springs.ox[index] - worldX) * scale,
            boxY + (scene.by[index] + scene.springs.oy[index] - worldY) * scale,
            Math.min(4, Math.max(0.8, scene.nodes[index].radius * scale)),
            0,
            Math.PI * 2,
        );
        context.fillStyle = rgba(nodeColour(scene, palette, index), alpha);
        context.fill();
    }

    const { camera } = scene;
    const viewX = boxX + (-camera.x / camera.k - worldX) * scale;
    const viewY = boxY + (-camera.y / camera.k - worldY) * scale;
    const viewWidth = (scene.width / camera.k) * scale;
    const viewHeight = (scene.height / camera.k) * scale;
    context.beginPath();
    context.roundRect(viewX, viewY, viewWidth, viewHeight, 3);
    context.fillStyle = rgba(palette.primary, 0.08);
    context.fill();
    context.lineWidth = 1.5;
    context.strokeStyle = rgba(palette.primary, 0.8);
    context.stroke();
    context.restore();
}

export function BrainGraphCanvas({
    nodes,
    edges,
    areas,
    hiddenAreas,
    matchIds,
    hiddenIds,
    colourBy,
    pathIds,
    areaLabels,
    selectedId,
    onSelect,
    onTrace,
    onOpen,
    centreRequest,
    fitRequest,
}: Props) {
    const { t } = useTranslation();
    const containerRef = useRef<HTMLDivElement>(null);
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const [hasPinned, setHasPinned] = useState(false);
    const sceneRef = useRef<Scene>({
        nodes: [],
        indexById: new Map(),
        links: [],
        nodeArea: [],
        neighbours: null,
        matches: null,
        path: null,
        pathSet: null,
        areaLabels: null,
        hiddenAreas: new Set(),
        hiddenNodes: new Uint8Array(0),
        signals: new Uint8Array(0),
        fresh: new Float32Array(0),
        colourBy: 'area',
        selected: null,
        hovered: null,
        bx: new Float32Array(0),
        by: new Float32Array(0),
        springs: createSpringState(0),
        springsActive: false,
        dragged: null,
        glide: null,
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
        minimap: null,
        width: 0,
        height: 0,
        dpr: 1,
        palette: null,
        animate: !prefersReducedMotion(),
        dirty: true,
        fitted: false,
        labelWidths: new Map(),
        byDegree: [],
        positionsVersion: 0,
        hitGrid: null,
    });
    const callbacks = useRef({ onSelect, onOpen, onTrace });

    useEffect(() => {
        callbacks.current = { onSelect, onOpen, onTrace };
    }, [onSelect, onOpen, onTrace]);

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

    // Data: index the nodes, resolve links, colour by area, order the intro. The same items in a
    // new layout glide to their new places instead of starting over.
    useEffect(() => {
        const scene = sceneRef.current;
        const isSameItems =
            scene.fitted &&
            nodes.length > 0 &&
            scene.nodes.length === nodes.length &&
            nodes.every((node, index) => scene.nodes[index]?.id === node.id);

        if (isSameItems) {
            const toX = Float32Array.from(nodes.map((node) => node.x));
            const toY = Float32Array.from(nodes.map((node) => node.y));
            scene.nodes = nodes;
            scene.springs = createSpringState(nodes.length);
            scene.springsActive = false;
            scene.dragged = null;
            scene.glide = {
                fromX: Float32Array.from(scene.bx),
                fromY: Float32Array.from(scene.by),
                toX,
                toY,
                start: null,
            };
            scene.introStart = null;
            scene.positionsVersion += 1;
            moveCamera(fitCamera(scene, toX, toY));
            scene.dirty = true;

            return;
        }

        scene.nodes = nodes;
        scene.indexById = new Map(nodes.map((node, index) => [node.id, index]));
        scene.links = edges.flatMap((edge): Array<[number, number]> => {
            const from = scene.indexById.get(edge.from);
            const to = scene.indexById.get(edge.to);

            return from === undefined || to === undefined ? [] : [[from, to]];
        });
        scene.nodeArea = nodes.map((node) => areaIndex(node.area, areas));
        const now = Date.now();
        scene.signals = Uint8Array.from(
            nodes.map((node) =>
                node.open_proposals + node.open_gaps > 0 ? 1 : 0,
            ),
        );
        scene.fresh = Float32Array.from(
            nodes.map((node) => freshness(node.changed_at, now)),
        );
        scene.hiddenNodes = new Uint8Array(nodes.length);
        scene.bx = Float32Array.from(nodes.map((node) => node.x));
        scene.by = Float32Array.from(nodes.map((node) => node.y));
        scene.springs = createSpringState(nodes.length);
        scene.springsActive = false;
        scene.glide = null;
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
        scene.byDegree = nodes
            .map((_, index) => index)
            .sort(
                (first, second) => nodes[second].degree - nodes[first].degree,
            );
        scene.positionsVersion += 1;
        scene.introStart = null;
        scene.lastTime = null;
        scene.fitted = false;
        scene.dirty = true;
    }, [nodes, edges, areas, moveCamera]);

    // A new layout clears dropped nodes, so the Reset layout button goes too.
    useEffect(() => {
        setHasPinned(false);
    }, [nodes]);

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
            if (scene.selected !== index) {
                scene.pulseStart = performance.now() / 1000;
            }

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
        scene.hiddenNodes = new Uint8Array(scene.nodes.length);

        for (const id of hiddenIds ?? []) {
            const index = scene.indexById.get(id);

            if (index !== undefined) {
                scene.hiddenNodes[index] = 1;
            }
        }

        scene.colourBy = colourBy;
        scene.positionsVersion += 1;
        scene.dirty = true;
    }, [hiddenIds, colourBy, nodes]);

    useEffect(() => {
        const scene = sceneRef.current;
        scene.hiddenAreas = new Set(
            hiddenAreas.map((area) => areaIndex(area, areas)),
        );
        scene.dirty = true;
    }, [hiddenAreas, areas, nodes]);

    useEffect(() => {
        const scene = sceneRef.current;
        const toIndexes = (ids: string[]): number[] =>
            ids.flatMap((id) => {
                const index = scene.indexById.get(id);

                return index === undefined ? [] : [index];
            });
        scene.matches = matchIds === null ? null : new Set(toIndexes(matchIds));
        scene.path = pathIds === null ? null : toIndexes(pathIds);
        scene.pathSet = scene.path === null ? null : new Set(scene.path);
        scene.areaLabels = areaLabels;
        scene.dirty = true;
    }, [matchIds, pathIds, areaLabels, nodes]);

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

                if (scene.selected !== null && scene.pathSet === null) {
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

    // Pointer: drag a node to move it, drag empty space to pan, the minimap moves the view,
    // a click without movement selects (Shift traces a path), wheel zooms around the cursor.
    useEffect(() => {
        const canvas = canvasRef.current;

        if (canvas === null) {
            return;
        }

        const scene = sceneRef.current;
        type Point = { x: number; y: number };
        type Drag = {
            mode: 'pan' | 'node' | 'minimap';
            node: number | null;
            x: number;
            y: number;
            camera: Camera;
            moved: boolean;
            shift: boolean;
        };
        let drag: Drag | null = null;

        const local = (event: MouseEvent): Point => {
            const rect = canvas.getBoundingClientRect();

            return {
                x: event.clientX - rect.left,
                y: event.clientY - rect.top,
            };
        };

        const toWorld = (point: Point): Point => ({
            x: (point.x - scene.camera.x) / scene.camera.k,
            y: (point.y - scene.camera.y) / scene.camera.k,
        });

        const inMinimap = (point: Point): boolean => {
            const map = scene.minimap;

            return (
                map !== null &&
                point.x >= map.x &&
                point.x <= map.x + map.width &&
                point.y >= map.y &&
                point.y <= map.y + map.height
            );
        };

        /** Centres the view on the world point under `point` in the minimap. */
        const lookAtMinimap = (point: Point): void => {
            const map = scene.minimap;

            if (map === null) {
                return;
            }

            const worldX = map.worldX + (point.x - map.x) / map.scale;
            const worldY = map.worldY + (point.y - map.y) / map.scale;
            scene.camera = {
                ...scene.camera,
                x: scene.width / 2 - worldX * scene.camera.k,
                y: scene.height / 2 - worldY * scene.camera.k,
            };
            scene.target = null;
            scene.dirty = true;
        };

        /** The hit grid over the laid out places, rebuilt only after they move. */
        const hitGrid = (): HitGrid => {
            if (
                scene.hitGrid !== null &&
                scene.hitGrid.version === scene.positionsVersion
            ) {
                return scene.hitGrid;
            }

            const cells = new Map<number, number[]>();

            for (let index = 0; index < scene.nodes.length; index++) {
                const key = hitCellKey(
                    Math.floor(
                        (scene.bx[index] + scene.springs.ox[index]) / HIT_CELL,
                    ),
                    Math.floor(
                        (scene.by[index] + scene.springs.oy[index]) / HIT_CELL,
                    ),
                );
                const cell = cells.get(key) ?? [];
                cell.push(index);
                cells.set(key, cell);
            }

            scene.hitGrid = { version: scene.positionsVersion, cells };

            return scene.hitGrid;
        };

        const hitTest = (point: Point): number | null => {
            const world = toWorld(point);
            let best: number | null = null;
            let bestDistance = Infinity;
            const { cells } = hitGrid();
            const column = Math.floor(world.x / HIT_CELL);
            const row = Math.floor(world.y / HIT_CELL);
            const candidates: number[] = [];

            // Nodes float a few pixels off their place: the 3×3 cells around the pointer cover it.
            for (let dx = -1; dx <= 1; dx++) {
                for (let dy = -1; dy <= 1; dy++) {
                    candidates.push(
                        ...(cells.get(hitCellKey(column + dx, row + dy)) ?? []),
                    );
                }
            }

            for (const index of candidates) {
                if (!isShown(scene, index)) {
                    continue;
                }

                const distance = Math.hypot(
                    scene.px[index] - world.x,
                    scene.py[index] - world.y,
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

            const point = local(event);
            const onMinimap = inMinimap(point);
            const node = onMinimap ? null : hitTest(point);
            drag = {
                mode: onMinimap ? 'minimap' : node === null ? 'pan' : 'node',
                node,
                ...point,
                camera: { ...scene.camera },
                moved: false,
                shift: event.shiftKey,
            };
            scene.target = null;

            if (onMinimap) {
                lookAtMinimap(point);
            }

            // Keep the drag when the pointer leaves the canvas; some synthetic pointers cannot be captured.
            try {
                canvas.setPointerCapture(event.pointerId);
            } catch {
                // The drag still works while the pointer stays over the canvas.
            }
        };

        const onPointerMove = (event: PointerEvent): void => {
            const point = local(event);

            if (drag !== null) {
                if (drag.mode === 'minimap') {
                    lookAtMinimap(point);

                    return;
                }

                const dx = point.x - drag.x;
                const dy = point.y - drag.y;

                if (!drag.moved && Math.hypot(dx, dy) <= 3) {
                    return;
                }

                drag.moved = true;
                canvas.style.cursor = 'grabbing';

                if (drag.mode === 'node' && drag.node !== null) {
                    // The node follows the pointer; the springs drag its neighbours along.
                    const world = toWorld(point);
                    const { springs } = scene;
                    scene.dragged = drag.node;
                    springs.pinned[drag.node] = 1;
                    springs.ox[drag.node] = world.x - scene.bx[drag.node];
                    springs.oy[drag.node] = world.y - scene.by[drag.node];
                    scene.springsActive = true;
                } else {
                    scene.camera = {
                        ...drag.camera,
                        x: drag.camera.x + dx,
                        y: drag.camera.y + dy,
                    };
                }

                scene.dirty = true;

                return;
            }

            if (inMinimap(point)) {
                canvas.style.cursor = 'crosshair';

                if (scene.hovered !== null) {
                    scene.hovered = null;
                    scene.dirty = true;
                }

                return;
            }

            const hovered = hitTest(point);

            if (hovered !== scene.hovered) {
                scene.hovered = hovered;
                scene.dirty = true;
            }

            canvas.style.cursor = hovered === null ? 'grab' : 'pointer';
        };

        const onPointerUp = (event: PointerEvent): void => {
            if (drag === null) {
                return;
            }

            const finished = drag;
            drag = null;
            canvas.style.cursor = scene.hovered === null ? 'grab' : 'pointer';

            if (finished.mode === 'node' && finished.moved) {
                // Dropped: it stays where it was put (pinned), its neighbours settle around it.
                scene.dragged = null;
                scene.springsActive = true;
                setHasPinned(true);

                return;
            }

            if (finished.mode !== 'minimap' && !finished.moved) {
                const hit = hitTest(local(event));

                if (hit !== null && (finished.shift || event.shiftKey)) {
                    callbacks.current.onTrace(scene.nodes[hit].id);
                } else {
                    callbacks.current.onSelect(
                        hit === null ? null : scene.nodes[hit].id,
                    );
                }
            }
        };

        const onPointerLeave = (): void => {
            if (scene.hovered !== null) {
                scene.hovered = null;
                scene.dirty = true;
            }
        };

        const onDoubleClick = (event: MouseEvent): void => {
            const point = local(event);

            if (inMinimap(point)) {
                return;
            }

            const hit = hitTest(point);

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

    /** Saves what the canvas shows as a PNG, on the card background (#290). */
    const exportImage = (): void => {
        const canvas = canvasRef.current;
        const palette = sceneRef.current.palette;

        if (canvas === null || palette === null) {
            return;
        }

        const image = document.createElement('canvas');
        image.width = canvas.width;
        image.height = canvas.height;
        const context = image.getContext('2d');

        if (context === null) {
            return;
        }

        context.fillStyle = rgba(palette.card, 1);
        context.fillRect(0, 0, image.width, image.height);
        context.drawImage(canvas, 0, 0);
        image.toBlob((blob) => {
            if (blob === null) {
                return;
            }

            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `instituta-graph-${new Date().toISOString().slice(0, 10)}.png`;
            link.click();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        }, 'image/png');
    };

    /** Lets every dropped node go: the springs pull everything back to the layout. */
    const resetLayout = (): void => {
        const scene = sceneRef.current;
        scene.springs.pinned.fill(0);
        scene.springsActive = true;
        scene.dirty = true;
        setHasPinned(false);
    };

    /**
     * Arrow keys walk the graph: from the selection, the linked item that lies most in that
     * direction (the nearest visible item in that direction when no link goes that way); with no
     * selection, the biggest hub. The camera follows when the item leaves the middle of the view.
     */
    const step = (dx: number, dy: number): void => {
        const scene = sceneRef.current;
        const from = scene.selected;

        if (from === null) {
            const hub = scene.byDegree.find((index) => isShown(scene, index));

            if (hub !== undefined) {
                onSelect(scene.nodes[hub].id);
                moveCamera(cameraOn(scene, hub));
            }

            return;
        }

        const score = (index: number): number => {
            const vx = scene.px[index] - scene.px[from];
            const vy = scene.py[index] - scene.py[from];
            const distance = Math.hypot(vx, vy);

            if (distance === 0) {
                return -Infinity;
            }

            const alignment = (vx * dx + vy * dy) / distance;

            // Within ~60° of the arrow; closer and straighter wins.
            return alignment < 0.5 ? -Infinity : alignment * 2 - distance / 400;
        };
        const pick = (candidates: Iterable<number>): number | null => {
            let best: number | null = null;
            let bestScore = -Infinity;

            for (const index of candidates) {
                if (index === from || !isShown(scene, index)) {
                    continue;
                }

                const value = score(index);

                if (value > bestScore) {
                    best = index;
                    bestScore = value;
                }
            }

            return best;
        };
        const next =
            pick(scene.neighbours ?? []) ??
            pick(scene.nodes.map((_, index) => index));

        if (next === null) {
            return;
        }

        onSelect(scene.nodes[next].id);

        const screenX = scene.px[next] * scene.camera.k + scene.camera.x;
        const screenY = scene.py[next] * scene.camera.k + scene.camera.y;

        if (
            screenX < scene.width * 0.2 ||
            screenX > scene.width * 0.85 ||
            screenY < scene.height * 0.15 ||
            screenY > scene.height * 0.85
        ) {
            moveCamera(cameraOn(scene, next));
        }
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLCanvasElement>): void => {
        const scene = sceneRef.current;
        const arrows: Record<string, [number, number]> = {
            ArrowLeft: [-1, 0],
            ArrowRight: [1, 0],
            ArrowUp: [0, -1],
            ArrowDown: [0, 1],
        };

        if (event.key in arrows) {
            const [dx, dy] = arrows[event.key];
            step(dx, dy);
        } else if (event.key === 'Escape') {
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

    const controlClass = 'size-8 bg-card/80 backdrop-blur';
    const selectedNode =
        selectedId === null
            ? undefined
            : nodes.find((node) => node.id === selectedId);
    const announcement =
        selectedNode === undefined
            ? ''
            : t('brain.graph.selectedAnnouncement', {
                  title: selectedNode.title,
                  area: t(`brain.areas.${selectedNode.area}`, {
                      defaultValue: selectedNode.area,
                  }),
                  count: selectedNode.degree,
              });

    return (
        <div
            ref={containerRef}
            className="relative h-full min-h-[28rem] w-full overflow-hidden rounded-xl border bg-card"
            style={{
                backgroundImage:
                    'radial-gradient(ellipse at 50% 40%, color-mix(in oklab, var(--primary) 7%, transparent), transparent 70%)',
            }}
        >
            <a
                href={explorerIndex.url()}
                className="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-10 focus:rounded-md focus:border focus:bg-card focus:px-3 focus:py-1.5 focus:text-sm focus:shadow-sm"
            >
                {t('brain.graph.listAlternative')}
            </a>
            <p className="sr-only" aria-live="polite">
                {announcement}
            </p>
            <canvas
                ref={canvasRef}
                role="img"
                tabIndex={0}
                aria-label={t('brain.graph.canvasLabel')}
                aria-description={t('brain.graph.keyboardHint')}
                onKeyDown={onKeyDown}
                className="absolute inset-0 size-full cursor-grab touch-none rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            <div className="absolute right-3 bottom-3 flex flex-col gap-1">
                {hasPinned ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className={controlClass}
                        onClick={resetLayout}
                        aria-label={t('brain.graph.resetLayout')}
                        title={t('brain.graph.resetLayout')}
                    >
                        <Undo2 />
                    </Button>
                ) : null}
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className={controlClass}
                    onClick={exportImage}
                    aria-label={t('brain.graph.exportImage')}
                    title={t('brain.graph.exportImage')}
                >
                    <Download />
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className={controlClass}
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
                    className={controlClass}
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
                    className={controlClass}
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
