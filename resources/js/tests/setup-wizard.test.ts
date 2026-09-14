import { describe, expect, it } from 'vitest';
import { stepState, suggestCompanyCode } from '@/lib/setup';

/** Gap fix GA-18: the company step suggests a short code from the name (as the server does), and the Done step says each step's state in words. */
describe('setup wizard', () => {
    it('suggests the short code the server suggests', () => {
        expect(suggestCompanyCode('Gap Audit Insurance')).toBe('GAI');
        expect(suggestCompanyCode('Padma General Insurance PLC')).toBe('PGI');
        expect(suggestCompanyCode('The Acme Company Ltd.')).toBe('ACME');
        expect(suggestCompanyCode('Pragati')).toBe('PRAG');
        expect(suggestCompanyCode('Ltd')).toBe('LTD');
        expect(suggestCompanyCode('—')).toBe('');
    });

    it('names a step saved, still to do, or left for its owner', () => {
        expect(stepState({ done: true, allowed: true, owner: 'Tenant Admin' })).toBe('Saved');
        expect(stepState({ done: false, allowed: true, owner: 'Tenant Admin' })).toBe('Not saved yet');
        expect(stepState({ done: false, allowed: false, owner: 'Finance Manager' })).toBe('Left for the Finance Manager');
    });
});
