/**
 * Flow fix X9: creating a producer inline from a lookup. Who sees what in the lookup's footer, and the drawer's starting values: the quote's branch, an agent
 * holding a non-life licence issued today and valid for a year (the licence number and expiry are typed from the certificate). Pure functions, no Vue.
 */
export type ProducerType = 'agent' | 'agency_org' | 'bdo' | 'broker' | 'partner';

export const PRODUCER_TYPES: { value: ProducerType; label: string }[] = [
    { value: 'agent', label: 'Agent' },
    { value: 'agency_org', label: 'Agency' },
    { value: 'bdo', label: 'BDO' },
    { value: 'broker', label: 'Broker' },
    { value: 'partner', label: 'Partner' },
];

export const LICENCE_CLASSES = [
    { value: 'non_life', label: 'Non-life' },
    { value: 'life', label: 'Life' },
    { value: 'both', label: 'Life and non-life' },
];

/** The sentence a person who may not add producers sees in the producer lookup. */
export const ASK_FOR_PRODUCER = 'Ask your branch manager to add the producer.';

/** What the lookup offers below its results: create a customer, create a producer, a hint naming who can add producers, or nothing. */
export function lookupCreateMode(type: string, creatable: boolean, canManageProducers: boolean): 'customer' | 'producer' | 'ask' | null {
    if (!creatable) return null;
    if (type === 'customer') return 'customer';
    if (type === 'agent') return canManageProducers ? 'producer' : 'ask';
    return null;
}

export interface ProducerDraft {
    name: string;
    producer_type: ProducerType;
    code: string;
    branch_id: string;
    licence_no: string;
    licence_class: string;
    issued_on: string;
    expires_on: string;
}

/** The drawer's starting values: the typed name, an agent on the quote's branch, a non-life licence from today to a year less a day. */
export function producerDraft(name: string, branchId: string, today: string): ProducerDraft {
    const [y, m, d] = today.split('-').map(Number) as [number, number, number];
    const end = new Date(Date.UTC(y + 1, m - 1, d) - 86_400_000).toISOString().slice(0, 10);
    return { name, producer_type: 'agent', code: '', branch_id: branchId, licence_no: '', licence_class: 'non_life', issued_on: today, expires_on: end };
}
