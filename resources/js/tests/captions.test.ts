import { describe, expect, it } from 'vitest';
import { captionFor } from '@/lib/captions';

describe('journal line captions (session S5)', () => {
    const captions = { premium_receivable: { debit: 'Customer owes us the premium', credit: 'Customer paid, so owes us less' } };

    it('picks the caption by account role and side', () => {
        expect(captionFor(captions, 'premium_receivable', 'debit')).toBe('Customer owes us the premium');
        expect(captionFor(captions, 'premium_receivable', 'credit')).toBe('Customer paid, so owes us less');
    });

    it('has no caption for a line without a role or with an unknown role', () => {
        expect(captionFor(captions, null, 'debit')).toBeNull();
        expect(captionFor(captions, 'office_rent', 'debit')).toBeNull();
    });
});
