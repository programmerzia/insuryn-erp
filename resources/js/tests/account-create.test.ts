import { describe, expect, it } from 'vitest';
import { normalSideFor, withAccount } from '@/lib/accountCreate';

/** Flow fix X10: the normal side suggested from the account type, and the new account added to a journal line's choices. */
describe('inline account create', () => {
    it('suggests the normal side from the type', () => {
        expect(['asset', 'expense'].map(normalSideFor)).toEqual(['debit', 'debit']);
        expect(['liability', 'equity', 'income'].map(normalSideFor)).toEqual(['credit', 'credit', 'credit']);
    });

    it('adds the new account in code order once', () => {
        const accounts = [{ id: 'a', code: '1000', name: 'Cash', is_control: false }, { id: 'c', code: '6100', name: 'Rent', is_control: false }];
        const created = { id: 'b', code: '6050', name: 'Office cleaning', is_control: false };
        expect(withAccount(accounts, created).map((a) => a.code)).toEqual(['1000', '6050', '6100']);
        expect(withAccount(withAccount(accounts, created), created)).toHaveLength(3);
    });
});
