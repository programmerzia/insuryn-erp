import { describe, expect, it } from 'vitest';
import { blankCreateDraft, createPayload, identityLabel, lookupCreateConfig } from '@/lib/lookupCreate';

describe('inline create from a lookup (flow fix X8)', () => {
    it('creates customers and payees, and nothing else', () => {
        expect(lookupCreateConfig('customer')).toEqual({ noun: 'customer', endpoint: '/lookup/customer', roles: null, contact: true });
        expect(lookupCreateConfig('payee')?.contact).toBeUndefined();
        expect(lookupCreateConfig('payee')?.endpoint).toBe('/lookup/payee');
        expect(lookupCreateConfig('payee')?.roles?.map((r) => r.value)).toEqual(['vendor', 'beneficiary']);
        expect(lookupCreateConfig('policy')).toBeNull();
        expect(lookupCreateConfig('agent')).toBeNull();
        expect(lookupCreateConfig('installment')).toBeNull();
    });

    it('starts a customer as an individual and a payee as an organisation vendor, named from what was typed', () => {
        expect(blankCreateDraft(lookupCreateConfig('customer'), 'Nazmul')).toEqual({ display_name: 'Nazmul', kind: 'individual', tax_id: '', role: '', mobile: '', email: '', identity_no: '' });
        expect(blankCreateDraft(lookupCreateConfig('payee'), 'Rahman Motors')).toEqual({ display_name: 'Rahman Motors', kind: 'organization', tax_id: '', role: 'vendor' });
    });

    it('sends an empty tax ID as null, the role only for payees, and the claim the payee is for', () => {
        const customer = lookupCreateConfig('customer')!;
        const payee = lookupCreateConfig('payee')!;
        expect(createPayload(customer, { display_name: 'A', kind: 'individual', tax_id: ' ', role: '' })).toEqual({ display_name: 'A', kind: 'individual', tax_id: null, mobile: null, email: null, identity_no: null });
        // GA-17: a customer's contact details go with it, trimmed; empty ones as null.
        expect(createPayload(customer, { display_name: 'B', kind: 'individual', tax_id: '', role: '', mobile: ' 01712 345678 ', email: '', identity_no: '1990123456789' }))
            .toEqual({ display_name: 'B', kind: 'individual', tax_id: null, mobile: '01712 345678', email: null, identity_no: '1990123456789' });
        expect(identityLabel('individual')).toBe('National ID (NID)');
        expect(identityLabel('organization')).toBe('Business registration number (BRN)');
        expect(createPayload(payee, { display_name: 'Garage', kind: 'organization', tax_id: 'TIN-1', role: 'vendor' }, { claim_id: 'c-1' }))
            .toEqual({ claim_id: 'c-1', display_name: 'Garage', kind: 'organization', tax_id: 'TIN-1', role: 'vendor' });
    });
});
