import { describe, expect, it, vi } from 'vitest';
import { initialReceipt, receiptAllocates, receiptPayload } from '@/lib/receiptForm';
import { confirmationToast, followStep } from '@/lib/toasts';

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
        expect(visit).toHaveBeenCalledWith({ label: 'Record receipt', url: '/receipts/create?policy=p1', prompt: 'Record the premium receipt?' });
    });

    it('follows a step by visiting, posting (print receipt) or downloading (the printed file)', () => {
        const handlers = { visit: vi.fn(), post: vi.fn(), download: vi.fn() };
        followStep({ label: 'Record receipt', url: '/receipts/create?policy=p1' }, handlers);
        followStep({ label: 'Print receipt', url: '/receipts/r1/generated-documents', method: 'post' }, handlers);
        followStep({ label: 'Download', url: '/receipts/r1/documents/d1', method: 'download' }, handlers);
        expect(handlers.visit).toHaveBeenCalledWith('/receipts/create?policy=p1');
        expect(handlers.post).toHaveBeenCalledWith('/receipts/r1/generated-documents');
        expect(handlers.download).toHaveBeenCalledWith('/receipts/r1/documents/d1');
        expect([handlers.visit.mock.calls.length, handlers.post.mock.calls.length, handlers.download.mock.calls.length]).toEqual([1, 1, 1]);
    });

    it('offers Print receipt without a question when a receipt is recorded', () => {
        const { message, options } = confirmationToast('Receipt RCT-HO-2026-000001 recorded.', null, { label: 'Print receipt', url: '/receipts/r1/generated-documents', method: 'post' }, vi.fn(), vi.fn());
        expect(message).toBe('Receipt RCT-HO-2026-000001 recorded.');
        expect(options.action?.label).toBe('Print receipt');
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

describe('receipt posted by someone who does not allocate (GA-03)', () => {
    const data = { branch_id: 'ho', amount: '120,000.00', allocations: [{ installment_id: 'i1', amount: '120,000.00', outstanding: '120,000.00', label: 'POL-1 #1' }] };

    it('allocates only in the branches the user allocates in; without the list, as before', () => {
        expect(receiptAllocates(['ho'], 'ho')).toBe(true);
        expect(receiptAllocates([], 'ho')).toBe(false);
        expect(receiptAllocates(undefined, 'ho')).toBe(true);
    });

    it('sends no allocation lines and notes the policy when the user does not allocate', () => {
        expect(receiptPayload(data, false, 'p1')).toEqual({ branch_id: 'ho', amount: '120,000.00', allocations: [], for_policy_id: 'p1' });
        expect(receiptPayload(data, true, null)).toEqual({ branch_id: 'ho', amount: '120,000.00', allocations: [{ installment_id: 'i1', amount: '120,000.00' }], for_policy_id: null });
    });
});
