import { describe, expect, it, vi } from 'vitest';
import { initialReceipt } from '@/lib/receiptForm';
import { confirmationToast } from '@/lib/toasts';

/** Flow fix X1: after issuing, the confirmation offers the receipt; the receipt opened from a policy starts filled in. */
describe('confirmation toast with a next step', () => {
    it('asks the next step\'s question and runs its action by visiting the url', () => {
        const visit = vi.fn();
        const { message, options } = confirmationToast('Policy POL-HO-2026-000001 issued.', null,
            { label: 'Record receipt', url: '/receipts/create?policy=p1', prompt: 'Record the premium receipt?' }, visit, vi.fn());
        expect(message).toBe('Policy POL-HO-2026-000001 issued. Record the premium receipt?');
        expect(options.action?.label).toBe('Record receipt');
        expect(options.duration).toBe(10000);
        options.action?.run();
        expect(visit).toHaveBeenCalledWith('/receipts/create?policy=p1');
    });

    it('keeps a plain confirmation and an undo as they were', () => {
        expect(confirmationToast('Quote created.', null, null, vi.fn(), vi.fn())).toEqual({ message: 'Quote created.', options: { tone: 'ok', undo: undefined, action: undefined, duration: 4000 } });
        const reverse = vi.fn();
        const { options } = confirmationToast('Statement line matched.', { label: 'Undo', url: '/bank/lines/1/unmatch' }, null, vi.fn(), reverse);
        expect(options.duration).toBe(6000);
        options.undo?.();
        expect(reverse).toHaveBeenCalledWith('/bank/lines/1/unmatch');
    });
});

describe('initial receipt', () => {
    const branches = [{ id: 'ho' }, { id: 'ctg' }];
    const defaults = { branch_id: 'ctg', value_date: '2026-09-15', channel: 'cash' };

    it('takes the amount, lines and branch from the policy, today and the last channel from the defaults', () => {
        const start = initialReceipt({ policy: { id: 'p1', number: 'POL-1' }, amount: '120,000.00', branch_id: 'ho', allocations: [
            { installment_id: 'i1', label: 'POL-1 #1', amount: '60,000.00', outstanding: '60,000.00' },
            { installment_id: 'i2', label: 'POL-1 #2', amount: '60,000.00', outstanding: '60,000.00' },
        ] }, defaults, branches);
        expect(start).toEqual({ branch_id: 'ho', channel: 'cash', amount: '120,000.00', value_date: '2026-09-15', allocations: [
            { installment_id: 'i1', amount: '60,000.00', outstanding: '60,000.00', label: 'POL-1 #1' },
            { installment_id: 'i2', amount: '60,000.00', outstanding: '60,000.00', label: 'POL-1 #2' },
        ] });
    });

    it('starts unfilled with the user\'s branch, or the first branch when nothing decides it', () => {
        expect(initialReceipt(null, defaults, branches)).toEqual({ branch_id: 'ctg', channel: 'cash', amount: '', value_date: '2026-09-15', allocations: [] });
        expect(initialReceipt(null, { ...defaults, branch_id: null }, branches).branch_id).toBe('ho');
        expect(initialReceipt(null, { ...defaults, branch_id: 'closed' }, branches).branch_id).toBe('ho');
    });
});
