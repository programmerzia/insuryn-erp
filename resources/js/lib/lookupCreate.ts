/**
 * Inline create from a lookup (brief §4 "Ctrl+N to create inline in a drawer"): which lookups can create, what the drawer calls the new record,
 * where it posts and which party roles it offers. Customers become customer + policyholder; payees (flow fix X8) are a vendor such as a garage
 * or hospital, or a beneficiary, created while approving a claim payment. GA-17: a new customer also takes a mobile number, email and NID/BRN.
 */
/** GA-40: `party` is any active party (a producer's person or organisation); `refundable` a policy with money still refundable. GA-21: `payer` of a claim recovery. */
export type LookupType = 'customer' | 'agent' | 'policy' | 'installment' | 'payee' | 'account' | 'payer' | 'party' | 'refundable' | 'supplier' | 'claim';

export interface LookupCreateConfig {
    noun: string;
    endpoint: string;
    roles: { value: string; label: string }[] | null;
    /** GA-17: the drawer asks for contact details (customers). */
    contact?: boolean;
}

export interface LookupCreateDraft {
    display_name: string;
    kind: string;
    tax_id: string;
    role: string;
    mobile?: string;
    email?: string;
    identity_no?: string;
}

export function lookupCreateConfig(type: LookupType): LookupCreateConfig | null {
    if (type === 'customer') return { noun: 'customer', endpoint: '/lookup/customer', roles: null, contact: true };
    if (type === 'payee') {
        return { noun: 'payee', endpoint: '/lookup/payee', roles: [{ value: 'vendor', label: 'Vendor (garage, surveyor, hospital)' }, { value: 'beneficiary', label: 'Beneficiary' }] };
    }
    return null;
}

export function blankCreateDraft(config: LookupCreateConfig | null, name = ''): LookupCreateDraft {
    const draft: LookupCreateDraft = { display_name: name, kind: config?.roles ? 'organization' : 'individual', tax_id: '', role: config?.roles?.[0]?.value ?? '' };
    return config?.contact ? { ...draft, mobile: '', email: '', identity_no: '' } : draft;
}

/** Label of the identity number for the kind of party: a person's national ID, an organisation's business registration number. */
export function identityLabel(kind: string): string {
    return kind === 'organization' ? 'Business registration number (BRN)' : 'National ID (NID)';
}

/** The request body: empty optional fields are sent as null, the role only where the lookup offers roles, contact details only for customers, and the context (e.g. the claim) as given. */
export function createPayload(config: LookupCreateConfig, draft: LookupCreateDraft, context: Record<string, string> = {}): Record<string, string | null> {
    const blank = (value: string | undefined) => (value === undefined || value.trim() === '' ? null : value.trim());
    const body: Record<string, string | null> = { display_name: draft.display_name, kind: draft.kind, tax_id: draft.tax_id.trim() === '' ? null : draft.tax_id };
    if (config.roles) body.role = draft.role;
    if (config.contact) {
        body.mobile = blank(draft.mobile);
        body.email = blank(draft.email);
        body.identity_no = blank(draft.identity_no);
    }
    return { ...context, ...body };
}
