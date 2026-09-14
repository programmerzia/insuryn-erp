import { describe, expect, it } from 'vitest';
import { pendingHeadline } from '@/lib/closePending';

describe('pending documents at close (slice 2.1b)', () => {
    it('says how many documents dated in the month still wait', () => {
        expect(pendingHeadline(1, 'September 2026')).toBe('1 document dated in September 2026 is still waiting');
        expect(pendingHeadline(3, 'September 2026')).toBe('3 documents dated in September 2026 are still waiting');
    });
});
