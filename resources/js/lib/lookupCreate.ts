/**
 * Inline create from a lookup (brief §4 "Ctrl+N to create inline in a drawer"): which lookups can create, what the drawer calls the new record,
 * where it posts and which party roles it offers. Customers become customer + policyholder; payees (flow fix X8) are a vendor such as a garage
 * or hospital, or a beneficiary, created while approving a claim payment.
 */
export type LookupType = 'customer' | 'agent' | 'policy' | 'installment' | 'payee';

export interface LookupCreateConfig {
    noun: string;
    endpoint: string;
    roles: { value: string; label: string }[] | null;
}

export interface LookupCreateDraft {
    display_name: string;
    kind: string;
    tax_id: string;
    role: string;
}

export function lookupCreateConfig(type: LookupType): LookupCreateConfig | null {
    if (type === 'customer') return { noun: 'customer', endpoint: '/lookup/customer', roles: null };
    if (type === 'payee') {
        return { noun: 'payee', endpoint: '/lookup/payee', roles: [{ value: 'vendor', label: 'Vendor (garage, surveyor, hospital)' }, { value: 'beneficiary', label: 'Beneficiary' }] };
    }
    return null;
}

export function blankCreateDraft(config: LookupCreateConfig | null, name = ''): LookupCreateDraft {
    return { display_name: name, kind: config?.roles ? 'organization' : 'individual', tax_id: '', role: config?.roles?.[0]?.value ?? '' };
}

/** The request body: an empty tax ID is sent as null, the role only where the lookup offers roles, and the context (e.g. the claim) as given. */
export function createPayload(config: LookupCreateConfig, draft: LookupCreateDraft, context: Record<string, string> = {}): Record<string, string | null> {
    const body: Record<string, string | null> = { display_name: draft.display_name, kind: draft.kind, tax_id: draft.tax_id.trim() === '' ? null : draft.tax_id };
    if (config.roles) body.role = draft.role;
    return { ...context, ...body };
}
