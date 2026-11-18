/**
 * Pure helpers for the brain graph renderer (#270): colours resolved from theme tokens, the gentle
 * float of each node, and label placement without overlaps. Kept apart from the canvas component
 * so they stay small and testable.
 */

export type Rgb = [number, number, number];

export type Palette = {
    areas: Rgb[];
    card: Rgb;
    border: Rgb;
    foreground: Rgb;
    mutedForeground: Rgb;
    primary: Rgb;
    warning: Rgb;
    fontFamily: string;
};

/** Area colours: the chart tokens, in a fixed order so an area keeps its colour. */
export const AREA_TOKENS = [
    '--chart-1',
    '--chart-2',
    '--chart-3',
    '--chart-4',
    '--chart-5',
];

export function areaIndex(area: string, areas: string[]): number {
    return Math.max(0, areas.indexOf(area)) % AREA_TOKENS.length;
}

/** `amount` of `target` mixed into `base`, channel by channel. */
export function mixRgb(base: Rgb, target: Rgb, amount: number): Rgb {
    return [
        Math.round(base[0] + (target[0] - base[0]) * amount),
        Math.round(base[1] + (target[1] - base[1]) * amount),
        Math.round(base[2] + (target[2] - base[2]) * amount),
    ];
}

export function rgba(colour: Rgb, alpha: number): string {
    return `rgba(${colour[0]}, ${colour[1]}, ${colour[2]}, ${alpha})`;
}

/**
 * Reads the theme tokens from `element` as RGB: a probe resolves `var(--token)` to a colour, and a
 * 1×1 canvas turns any CSS colour (hex, oklch, color()) into channels the renderer can mix.
 * The chart colours are softened towards the card, so the graph reads calm rather than loud.
 */
export function readPalette(element: HTMLElement): Palette {
    const probe = document.createElement('span');
    probe.style.display = 'none';
    element.appendChild(probe);

    const pixel = document.createElement('canvas');
    pixel.width = 1;
    pixel.height = 1;
    const context = pixel.getContext('2d', { willReadFrequently: true });

    const resolve = (token: string): Rgb => {
        probe.style.color = `var(${token})`;
        const css = getComputedStyle(probe).color;

        if (context === null) {
            return [128, 128, 128];
        }

        context.clearRect(0, 0, 1, 1);
        context.fillStyle = '#808080';
        context.fillStyle = css;
        context.fillRect(0, 0, 1, 1);
        const [red, green, blue] = context.getImageData(0, 0, 1, 1).data;

        return [red, green, blue];
    };

    const card = resolve('--card');
    const palette: Palette = {
        areas: AREA_TOKENS.map((token) => mixRgb(resolve(token), card, 0.22)),
        card,
        border: resolve('--border'),
        foreground: resolve('--foreground'),
        mutedForeground: resolve('--muted-foreground'),
        primary: resolve('--primary'),
        warning: resolve('--warning'),
        fontFamily: getComputedStyle(element).fontFamily,
    };

    probe.remove();

    return palette;
}

/**
 * How far a node drifts from its place at `time` (seconds): a slow, small loop with its own phase
 * and pace, so the graph breathes without the layout ever changing.
 */
export function floatOffset(
    index: number,
    time: number,
    amplitude: number,
): { dx: number; dy: number } {
    const phase = index * 2.399963; // the golden angle spreads phases evenly
    const pace = 0.35 + ((index * 0.618034) % 1) * 0.3;

    return {
        dx: Math.sin(time * pace + phase) * amplitude,
        dy: Math.cos(time * pace * 0.8 + phase * 1.3) * amplitude,
    };
}

export type LabelBox = { x: number; y: number; width: number; height: number };

export function boxesOverlap(
    first: LabelBox,
    second: LabelBox,
    gap = 4,
): boolean {
    return (
        first.x < second.x + second.width + gap &&
        second.x < first.x + first.width + gap &&
        first.y < second.y + second.height + gap &&
        second.y < first.y + first.height + gap
    );
}

export function truncateLabel(title: string, max = 32): string {
    return title.length > max ? `${title.slice(0, max - 1).trimEnd()}…` : title;
}

export function clamp01(value: number): number {
    return Math.min(1, Math.max(0, value));
}

export function easeOutCubic(progress: number): number {
    return 1 - Math.pow(1 - clamp01(progress), 3);
}

/** Overshoots a little before settling: nodes "pop" into place. */
export function easeOutBack(progress: number): number {
    const clamped = clamp01(progress);
    const overshoot = 1.70158;

    return (
        1 +
        (overshoot + 1) * Math.pow(clamped - 1, 3) +
        overshoot * Math.pow(clamped - 1, 2)
    );
}

/**
 * How fresh an item is, from when its content last changed: 1 for today, falling on a log scale
 * to 0 at a year or more (or never). Recent weeks stay apart; old months blur together.
 */
export function freshness(changedAt: string | null, now: number): number {
    if (changedAt === null) {
        return 0;
    }

    const days = Math.max(0, (now - Date.parse(changedAt)) / 86_400_000);

    return Number.isFinite(days)
        ? clamp01(1 - Math.log1p(days) / Math.log1p(365))
        : 0;
}
