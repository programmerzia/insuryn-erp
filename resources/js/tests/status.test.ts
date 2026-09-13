import { describe, expect, it } from 'vitest';
import { statusTone, statusWord } from '@/lib/status';

describe('status dot and word', () => {
    it('keeps danger for failures, unbalanced and variances only', () => {
        expect(statusTone('failed')).toBe('danger');
        expect(statusTone('variance')).toBe('danger');
        expect(statusTone('rejected')).toBe('neutral');
        expect(statusTone('pending_approval')).toBe('warn');
        expect(statusTone('posted')).toBe('ok');
    });

    it('writes statuses in sentence case', () => {
        expect(statusWord('pending_approval')).toBe('Pending approval');
        expect(statusWord('release_requested')).toBe('Release requested');
    });
});
