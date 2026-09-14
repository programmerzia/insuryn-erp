import { describe, expect, it } from 'vitest';
import { ASK_FOR_PRODUCER, lookupCreateMode, producerDraft } from '@/lib/producerCreate';

/** Flow fix X9: the producer lookup offers "New producer" only to people who may add producers, and the drawer starts from the quote. */
describe('inline producer create', () => {
    it('offers a producer create to holders of agent.manage and tells others who can add one', () => {
        expect(lookupCreateMode('agent', true, true)).toBe('producer');
        expect(lookupCreateMode('agent', true, false)).toBe('ask');
        expect(lookupCreateMode('agent', false, true)).toBeNull();
        expect(lookupCreateMode('customer', true, false)).toBe('customer');
        expect(lookupCreateMode('policy', true, true)).toBeNull();
        expect(ASK_FOR_PRODUCER).toBe('Ask your branch manager to add the producer.');
    });

    it('starts from the typed name, the quote branch and a non-life licence valid for a year from today', () => {
        expect(producerDraft('Jamal Uddin', 'branch-1', '2026-09-15')).toEqual({
            name: 'Jamal Uddin', producer_type: 'agent', code: '', branch_id: 'branch-1', licence_no: '', licence_class: 'non_life', issued_on: '2026-09-15', expires_on: '2027-09-14',
        });
        expect(producerDraft('', 'b', '2028-02-29').expires_on).toBe('2029-02-28');
    });
});
