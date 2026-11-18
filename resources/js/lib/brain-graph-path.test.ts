import { describe, expect, it } from 'vite-plus/test';
import { shortestPath } from './brain-graph-path';

const edges = [
    { from: 'brand', to: 'rules' },
    { from: 'rules', to: 'plans' },
    { from: 'brand', to: 'ideas' },
    { from: 'ideas', to: 'plans' },
    { from: 'plans', to: 'design' },
];

const isAlwaysAllowed = (): boolean => true;

describe('shortestPath', () => {
    it('returns the item itself when both ends are the same item', () => {
        expect(shortestPath('brand', 'brand', edges, isAlwaysAllowed)).toEqual([
            'brand',
        ]);
    });

    it('walks links in both directions and takes the fewest steps', () => {
        expect(shortestPath('design', 'brand', edges, isAlwaysAllowed)).toEqual(
            ['design', 'plans', 'rules', 'brand'],
        );
    });

    it('routes around items that are not allowed', () => {
        expect(
            shortestPath('brand', 'design', edges, (id) => id !== 'rules'),
        ).toEqual(['brand', 'ideas', 'plans', 'design']);
    });

    it('returns null when the items are not connected', () => {
        expect(
            shortestPath('brand', 'design', edges, (id) => id !== 'plans'),
        ).toBeNull();
        expect(
            shortestPath('brand', 'orphan', edges, isAlwaysAllowed),
        ).toBeNull();
    });
});
