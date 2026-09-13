/**
 * Money on the client (brief §1.7, CONTEXT.md non-negotiable #5): server amounts arrive formatted in major units; any arithmetic (footer
 * totals, selection sums, filters, money inputs) happens on BigInt minor units, never floats. Two decimals (BDT and every MVP currency).
 */
const DECIMALS = 2;
const SCALE = 10n ** BigInt(DECIMALS);

export function parseMoney(text: string | null | undefined): bigint | null {
    if (text === null || text === undefined) return null;
    let value = text.trim().replaceAll(',', '').replaceAll(' ', '');
    let negative = false;
    if (/^\(.*\)$/.test(value)) {
        negative = true;
        value = value.slice(1, -1);
    }
    if (value.startsWith('-')) {
        negative = !negative;
        value = value.slice(1);
    }
    const match = value.match(/^(\d+)(?:\.(\d{1,2}))?$/);
    if (!match) return null;
    const minor = BigInt(match[1] ?? '0') * SCALE + BigInt((match[2] ?? '').padEnd(DECIMALS, '0') || '0');
    return negative ? -minor : minor;
}

export function formatMinor(minor: bigint, options: { parentheses?: boolean } = {}): string {
    const negative = minor < 0n;
    const abs = negative ? -minor : minor;
    const whole = (abs / SCALE).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fraction = (abs % SCALE).toString().padStart(DECIMALS, '0');
    const text = `${whole}.${fraction}`;
    if (!negative) return text;
    return options.parentheses === false ? `-${text}` : `(${text})`;
}

export function sumMoney(values: (string | null | undefined)[]): bigint {
    return values.reduce<bigint>((total, value) => total + (parseMoney(value) ?? 0n), 0n);
}
