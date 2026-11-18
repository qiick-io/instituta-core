import { describe, expect, it } from 'vite-plus/test';
import { boxesOverlap, easeOutBack, mixRgb } from './brain-graph-render';

describe('mixRgb', () => {
    it('mixes the second colour into the first, channel by channel', () => {
        expect(mixRgb([0, 100, 200], [100, 200, 0], 0)).toEqual([0, 100, 200]);
        expect(mixRgb([0, 100, 200], [100, 200, 0], 0.25)).toEqual([
            25, 125, 150,
        ]);
        expect(mixRgb([0, 100, 200], [100, 200, 0], 1)).toEqual([100, 200, 0]);
    });
});

describe('boxesOverlap', () => {
    const box = { x: 0, y: 0, width: 10, height: 10 };

    it('counts boxes within the gap of each other as overlapping', () => {
        expect(boxesOverlap(box, { ...box, x: 13 })).toBe(true);
        expect(boxesOverlap({ ...box, y: 13 }, box)).toBe(true);
    });

    it('keeps boxes further apart than the gap separate', () => {
        expect(boxesOverlap(box, { ...box, x: 14 })).toBe(false);
        expect(boxesOverlap(box, { ...box, y: 14 })).toBe(false);
        expect(boxesOverlap(box, { ...box, x: 5 }, 0)).toBe(true);
        expect(boxesOverlap(box, { ...box, x: 10 }, 0)).toBe(false);
    });
});

describe('easeOutBack', () => {
    it('starts at 0, overshoots, and settles at 1', () => {
        expect(easeOutBack(0)).toBeCloseTo(0);
        expect(easeOutBack(0.6)).toBeGreaterThan(1);
        expect(easeOutBack(1)).toBe(1);
    });

    it('clamps progress outside 0..1', () => {
        expect(easeOutBack(-1)).toBeCloseTo(0);
        expect(easeOutBack(2)).toBe(1);
    });
});
