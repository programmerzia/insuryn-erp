import { describe, expect, it } from 'vitest';
import { navigation, visibleNavigation } from '@/lib/navigation';
import { type ChartAccount, deactivateBlocker, descendantsOf, editLocks, newAccountDraft, parentOptions, postingLabel } from '@/lib/chartOfAccounts';

/** UX U2: Accounting → Chart of accounts — the product owner found no way to add an account from the screens besides a CSV import. */
function account(over: Partial<ChartAccount> & { id: string; code: string }): ChartAccount {
    return { name: over.code, type: 'expense', normal_side: 'debit', parent_id: null, parent_code: null, depth: 0, is_postable: true, is_control: false, control_subledger: null,
        currency: null, status: 'active', roles: [], has_lines: false, balance_minor: 0, ...over };
}

const expenses = account({ id: 'e', code: '5000', name: 'Expenses', is_postable: false });
const office = account({ id: 'o', code: '5100', name: 'Office', parent_id: 'e', parent_code: '5000', depth: 1, is_postable: false });
const cleaning = account({ id: 'c', code: '5110', name: 'Cleaning', parent_id: 'o', parent_code: '5100', depth: 2 });
const bank = account({ id: 'b', code: '1010', name: 'Bank', type: 'asset', has_lines: true, balance_minor: 125_000, roles: [{ code: 'bank_main', description: 'Main bank account' }] });
const chart = [bank, expenses, office, cleaning];

describe('chart of accounts screen logic', () => {
    it('says how an account posts', () => {
        expect(postingLabel(cleaning)).toBe('Postable');
        expect(postingLabel(office)).toBe('Heading');
        expect(postingLabel({ is_postable: true, is_control: true, control_subledger: 'premium' })).toBe('Control · premium subledger');
    });

    it('never offers an account, or one of its own children, as its parent', () => {
        expect([...descendantsOf(chart, 'e')].sort()).toEqual(['c', 'e', 'o']);
        expect(parentOptions(chart, 'o').map((o) => o.value)).toEqual(['b', 'e']);
        expect(parentOptions(chart).map((o) => o.value)).toEqual(['b', 'e', 'o', 'c']);
        expect(parentOptions(chart)[3]?.label).toBe('  5110 · Cleaning');
        expect(parentOptions([{ ...cleaning, status: 'inactive' }])[0]?.label).toContain('(inactive)');
    });

    it('keeps type, side and postability once journal lines name the account (A-167)', () => {
        expect(editLocks(bank)).toEqual({ typeAndSide: true, postable: true });
        expect(editLocks(cleaning)).toEqual({ typeAndSide: false, postable: false });
        expect(editLocks({ has_lines: true, is_postable: false })).toEqual({ typeAndSide: true, postable: false });
    });

    it('explains why deactivating is off before asking the server (A-168)', () => {
        expect(deactivateBlocker(bank)).toBe('It has a balance. Move the balance to another account with a journal first.');
        expect(deactivateBlocker({ ...bank, balance_minor: 0 })).toBe('The accounting posts “Main bank account” to it. Map that role to another account first.');
        expect(deactivateBlocker(cleaning)).toBeNull();
    });

    it('starts a new account as a debit expense, or like the heading it goes under', () => {
        expect(newAccountDraft()).toMatchObject({ type: 'expense', normal_side: 'debit', parent_id: '', is_postable: true, is_control: false });
        expect(newAccountDraft({ ...expenses, type: 'income', normal_side: 'credit' })).toMatchObject({ type: 'income', normal_side: 'credit', parent_id: 'e' });
    });

    it('is in the sidebar for whoever can open it', () => {
        expect(navigation.find((n) => n.id === 'chart-of-accounts')?.href).toBe('/accounting/chart-of-accounts');
        expect(visibleNavigation(['accounting.view_journals']).some((n) => n.id === 'chart-of-accounts')).toBe(true);
        expect(visibleNavigation(['accounting.manage_coa']).some((n) => n.id === 'chart-of-accounts')).toBe(true);
        expect(visibleNavigation(['receipt.create']).some((n) => n.id === 'chart-of-accounts')).toBe(false);
    });
});
