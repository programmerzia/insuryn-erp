import { describe, expect, it } from 'vitest';
import { buildTree, canMoveUnder, flatten } from '@/lib/hierarchyTree';

const nodes = [
    { id: 'bm', code: 'BM-1', parent_id: null, level: 'BM' },
    { id: 'um1', code: 'UM-1', parent_id: 'bm', level: 'UM' },
    { id: 'um2', code: 'UM-2', parent_id: 'bm', level: 'UM' },
    { id: 'fa1', code: 'FA-1', parent_id: 'um1', level: 'FA' },
    { id: 'lost', code: 'X-9', parent_id: 'gone', level: null },
];

describe('hierarchy tree', () => {
    it('nests producers under their parents, roots first by code, orphans as roots', () => {
        const tree = buildTree(nodes);
        expect(tree.map((n) => n.code)).toEqual(['BM-1', 'X-9']);
        expect(tree[0]?.children.map((n) => n.code)).toEqual(['UM-1', 'UM-2']);
        expect(flatten(tree).map((n) => [n.code, n.depth])).toEqual([['BM-1', 0], ['UM-1', 1], ['FA-1', 2], ['UM-2', 1], ['X-9', 0]]);
    });

    it('never offers a move under the producer itself or its own team', () => {
        const tree = buildTree(nodes);
        expect(canMoveUnder(tree, 'um1', 'fa1')).toBe(false);
        expect(canMoveUnder(tree, 'um1', 'um1')).toBe(false);
        expect(canMoveUnder(tree, 'fa1', 'um2')).toBe(true);
        expect(canMoveUnder(tree, 'fa1', 'um1')).toBe(false); // already there
        expect(canMoveUnder(tree, 'um1', null)).toBe(true);
    });

    it('hides collapsed branches when flattening', () => {
        const tree = buildTree(nodes);
        expect(flatten(tree, new Set(['bm'])).map((n) => n.code)).toEqual(['BM-1', 'X-9']);
    });
});

describe('distribution words', async () => {
    const { blankZero, producerTypeLabel } = await import('@/lib/distribution');
    it('names producer types as people say them and blanks zero amounts', () => {
        expect(['agent', 'agency_org', 'bdo', null].map(producerTypeLabel)).toEqual(['Agent', 'Agency', 'BDO', 'Any']);
        expect(['0.00', '1,200.00', '-0.00'].map(blankZero)).toEqual([null, '1,200.00', null]);
    });
});
