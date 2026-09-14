import { describe, expect, it } from 'vitest';
import { approvalOutcome, stepLabel } from '@/lib/approvals';

describe('approvals inbox (GA-04)', () => {
    it('says which step the decision is', () => {
        expect(stepLabel(1, 2)).toBe('Step 1 of 2');
        expect(stepLabel(2, 2)).toBe('Step 2 of 2');
        expect(stepLabel(1, 1)).toBe('Only step');
        expect(stepLabel(3)).toBe('Step 3');
    });

    it('never says nothing is posted when the last approval posts', () => {
        expect(approvalOutcome(false, true)).toBe('Approving passes it to the next approver; nothing is posted yet.');
        expect(approvalOutcome(true, true)).toContain('approving posts the entries above to the ledger');
        expect(approvalOutcome(true, true)).not.toContain('nothing is posted');
        expect(approvalOutcome(true, false)).toBe('Yours is the last approval.');
    });
});
