/** Words for Distribution codes as people say them (UX brief §2 sentence case; BDO stays an acronym). */
const producerTypes: Record<string, string> = { agent: 'Agent', agency_org: 'Agency', bdo: 'BDO', broker: 'Broker', partner: 'Partner' };

export function producerTypeLabel(type: string | null | undefined): string {
    return type ? (producerTypes[type] ?? type) : 'Any';
}

export const producerTypeCodes = Object.keys(producerTypes);

/** A money cell that stays empty for nothing, so the figures that matter stand out (brief §1.2 exceptions first). */
export function blankZero(amount: string): string | null {
    return /^-?0(\.0+)?$/.test(amount.replaceAll(',', '')) ? null : amount;
}
