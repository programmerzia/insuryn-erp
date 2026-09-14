import { describe, expect, it } from 'vitest';
import { startingProduct } from '@/lib/lastProduct';

/** Flow fix X6: a new quote starts with the product the user quoted last. */
describe('starting product', () => {
    const products = [{ id: 'motor' }, { id: 'fire' }];

    it('keeps the product already chosen', () => {
        expect(startingProduct('fire', 'motor', products)).toBe('fire');
    });

    it('takes the last product while it can still be quoted', () => {
        expect(startingProduct('', 'fire', products)).toBe('fire');
        expect(startingProduct(null, 'marine', products)).toBe('');
    });

    it('falls back to the only product, and otherwise leaves the choice to the user', () => {
        expect(startingProduct(null, null, [{ id: 'motor' }])).toBe('motor');
        expect(startingProduct(null, 'gone', [{ id: 'motor' }])).toBe('motor');
        expect(startingProduct(undefined, null, products)).toBe('');
        expect(startingProduct(null, null, [])).toBe('');
    });
});
