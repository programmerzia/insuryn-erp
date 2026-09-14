import { describe, expect, it } from 'vitest';
import { captionFor } from '@/lib/captions';

describe('journal line captions (session S5)', () => {
    const captions = { premium_receivable: { debit: 'Customer owes us the premium', credit: 'Customer paid, so owes us less' } };

    it('picks the caption by account role and side', () => {
        expect(captionFor(captions, 'premium_receivable', 'debit')).toBe('Customer owes us the premium');
        expect(captionFor(captions, 'premium_receivable', 'credit')).toBe('Customer paid, so owes us less');
    });

    it('prefers the caption written for the journal\'s event (GA-24), else the role\'s own', () => {
        const withEvents = {
            unearned_premium: { debit: 'Cover has been provided', credit: 'Cover not yet provided' },
            'POLICY_CANCELLED:unearned_premium': { debit: 'Premium for cover not given', credit: 'Cover not yet provided' },
        };
        expect(captionFor(withEvents, 'unearned_premium', 'debit', 'POLICY_CANCELLED')).toBe('Premium for cover not given');
        expect(captionFor(withEvents, 'unearned_premium', 'debit', 'PREMIUM_EARNED')).toBe('Cover has been provided');
        expect(captionFor(withEvents, 'unearned_premium', 'debit')).toBe('Cover has been provided');
        expect(captionFor(withEvents, 'unearned_premium', 'debit', null)).toBe('Cover has been provided');
    });

    it('has no caption for a line without a role or with an unknown role', () => {
        expect(captionFor(captions, null, 'debit')).toBeNull();
        expect(captionFor(captions, 'office_rent', 'debit')).toBeNull();
    });
});
