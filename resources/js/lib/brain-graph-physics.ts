/**
 * The springs behind dragging in the brain graph (#275). Every node has an offset from its laid
 * out place: a spring pulls the offset back to zero, and every link pulls the offsets of its two
 * ends towards each other. Dragging (or pinning) a node moves its offset, so its neighbours follow
 * elastically, theirs a little less, and everything settles when released.
 */

export type SpringState = {
    ox: Float32Array;
    oy: Float32Array;
    vx: Float32Array;
    vy: Float32Array;
    /** 1 for nodes held in place (dragged now or dropped earlier). */
    pinned: Uint8Array;
};

const HOME_STIFFNESS = 10;
const LINK_STIFFNESS = 40;
const DAMPING = 7;
const STEP = 1 / 120;

export function createSpringState(count: number): SpringState {
    return {
        ox: new Float32Array(count),
        oy: new Float32Array(count),
        vx: new Float32Array(count),
        vy: new Float32Array(count),
        pinned: new Uint8Array(count),
    };
}

/**
 * Advances the springs by `dt` seconds in small fixed steps. Returns the fastest node speed, so
 * the caller can stop simulating once everything is at rest.
 */
export function stepSprings(
    state: SpringState,
    links: Array<[number, number]>,
    dt: number,
): number {
    const { ox, oy, vx, vy, pinned } = state;
    const count = ox.length;
    const ax = new Float32Array(count);
    const ay = new Float32Array(count);
    const steps = Math.min(6, Math.max(1, Math.round(dt / STEP)));
    let fastest = 0;

    for (let step = 0; step < steps; step++) {
        for (let index = 0; index < count; index++) {
            ax[index] = -HOME_STIFFNESS * ox[index] - DAMPING * vx[index];
            ay[index] = -HOME_STIFFNESS * oy[index] - DAMPING * vy[index];
        }

        for (const [from, to] of links) {
            const dx = (ox[to] - ox[from]) * LINK_STIFFNESS;
            const dy = (oy[to] - oy[from]) * LINK_STIFFNESS;
            ax[from] += dx;
            ay[from] += dy;
            ax[to] -= dx;
            ay[to] -= dy;
        }

        fastest = 0;

        for (let index = 0; index < count; index++) {
            if (pinned[index] === 1) {
                vx[index] = 0;
                vy[index] = 0;
                continue;
            }

            vx[index] += ax[index] * STEP;
            vy[index] += ay[index] * STEP;
            ox[index] += vx[index] * STEP;
            oy[index] += vy[index] * STEP;
            fastest = Math.max(
                fastest,
                Math.abs(vx[index]),
                Math.abs(vy[index]),
            );
        }
    }

    return fastest;
}
