import { formatMinor, parseMoney } from '@/lib/money';

/**
 * Tariff editor units (Phase 3 design §6, DECISION D-20). Rate tables store integers: `rate_pct` in basis points of a percent (1000 = 10.00 %),
 * `rate_pm` in hundredths of a per mille (225 = 2.25 ‰), `flat` in minor units (123456 = 1,234.56). People type and read the human figure; the
 * conversion works on the digits of the text — never through a float. Band tables hold whole-number ranges [from, to) with a label, and optionally a
 * rate in basis points for band_value() (ASSUMPTION: A-111 — band bounds are the raw whole numbers the step compares, a band value is edited as a percentage).
 */
export type RateValueType = 'rate_pct' | 'rate_pm' | 'flat' | 'band';

export interface StoredRow {
    keys: Record<string, string>;
    value_bp: number | null;
    value_minor: number | null;
    band_from: number | null;
    band_to: number | null;
    band_label: string | null;
    effective_from: string | null;
    effective_to: string | null;
}

/** A row as edited in the grid: every cell is the text in its input. */
export interface RowDraft {
    keys: Record<string, string>;
    value: string;
    band_from: string;
    band_to: string;
    band_label: string;
    effective_from: string;
    effective_to: string;
    /** A band row's amount value (value_minor) is kept as it was: the grid edits a band's rate. */
    kept_minor: number | null;
}

const groupThousands = (digits: string) => digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

/** "2.25", "2.25‰", "10%", "1,234.5" → hundredths (225, 1000, 123450); null unless a non-negative number with at most two decimals. */
export function parseHundredths(text: string, symbol: '%' | '‰'): number | null {
    const cleaned = text.trim().replace(symbol, '').trim().replaceAll(',', '');
    const match = cleaned.match(/^(\d{1,13})(?:\.(\d{1,2}))?$/);
    if (!match) return null;
    return Number(`${match[1]}${(match[2] ?? '').padEnd(2, '0')}`);
}

/** 225 → "2.25", 80 → "0.80", 123456 → "1,234.56". */
export function formatHundredths(value: number): string {
    const digits = String(Math.abs(value)).padStart(3, '0');
    return `${value < 0 ? '-' : ''}${groupThousands(digits.slice(0, -2))}.${digits.slice(-2)}`;
}

/** "1,301" → 1301; null unless a whole number (negative allowed only when asked). */
export function parseWhole(text: string, allowNegative = false): number | null {
    const cleaned = text.trim().replaceAll(',', '');
    if (!(allowNegative ? /^-?\d{1,15}$/ : /^\d{1,15}$/).test(cleaned)) return null;
    const value = Number(cleaned);
    return Number.isSafeInteger(value) ? value : null;
}

export function formatWhole(value: number | null): string {
    if (value === null) return '';
    return `${value < 0 ? '-' : ''}${groupThousands(String(Math.abs(value)))}`;
}

/** An amount typed in major units → minor units ("1,234.56" → 123456); null unless a non-negative amount with at most two decimals. */
export function parseAmount(text: string): number | null {
    const minor = parseMoney(text);
    if (minor === null || minor < 0n || minor > BigInt(Number.MAX_SAFE_INTEGER)) return null;
    return Number(minor);
}

export function formatAmount(minor: number | null): string {
    return minor === null ? '' : formatMinor(BigInt(minor), { parentheses: false });
}

/** The unit people read a table's values in. */
export function rateUnit(type: RateValueType, currency: string): string {
    return type === 'rate_pct' || type === 'band' ? '%' : type === 'rate_pm' ? '‰' : currency;
}

/** The typed value of a row for its table → the stored integer; null when the text is not a value in that unit. */
export function parseRate(type: RateValueType, text: string): number | null {
    if (type === 'flat') return parseAmount(text);
    return parseHundredths(text, type === 'rate_pm' ? '‰' : '%');
}

/** The stored value → the figure people read ("2.25", "10.00", "2,500.00"); empty when there is none. */
export function formatRate(type: RateValueType, row: Pick<StoredRow, 'value_bp' | 'value_minor'>): string {
    if (type === 'flat') return formatAmount(row.value_minor);
    if (row.value_bp !== null) return formatHundredths(row.value_bp);
    return row.value_minor === null ? '' : formatAmount(row.value_minor);
}

/** The stored value with its unit, for sentences and the diff: "2.25 ‰", "10.00 %", "2,500.00 BDT". */
export function rateWithUnit(type: RateValueType, row: Pick<StoredRow, 'value_bp' | 'value_minor'>, currency: string): string {
    const figure = formatRate(type, row);
    if (figure === '') return '—';
    const unit = type === 'flat' || (type === 'band' && row.value_bp === null) ? currency : rateUnit(type, currency);
    return `${figure} ${unit}`;
}

export function rowDraft(type: RateValueType, row: StoredRow): RowDraft {
    return {
        keys: { ...row.keys },
        value: type === 'band' ? (row.value_bp === null ? '' : formatHundredths(row.value_bp)) : formatRate(type, row),
        band_from: formatWhole(row.band_from),
        band_to: formatWhole(row.band_to),
        band_label: row.band_label ?? '',
        effective_from: row.effective_from ?? '',
        effective_to: row.effective_to ?? '',
        kept_minor: type === 'band' ? row.value_minor : null,
    };
}

export function emptyDraft(dimensions: string[]): RowDraft {
    return { keys: Object.fromEntries(dimensions.map((d) => [d, ''])), value: '', band_from: '', band_to: '', band_label: '', effective_from: '', effective_to: '', kept_minor: null };
}

/**
 * A grid row → the stored row the server takes, with a message per cell that cannot be read (keyed `keys.<dimension>`, `value`, `band_from`,
 * `band_to`, `band_label`, `effective_to`). The server still checks everything; this only keeps a typo from leaving the screen.
 */
export function rowPayload(type: RateValueType, dimensions: string[], draft: RowDraft): { row: StoredRow; errors: Record<string, string> } {
    const errors: Record<string, string> = {};
    const keys: Record<string, string> = {};
    for (const dimension of dimensions) {
        const value = (draft.keys[dimension] ?? '').trim();
        if (value === '') errors[`keys.${dimension}`] = 'Enter a value.';
        keys[dimension] = value;
    }
    const row: StoredRow = { keys, value_bp: null, value_minor: null, band_from: null, band_to: null, band_label: null, effective_from: draft.effective_from || null, effective_to: draft.effective_to || null };
    if (type === 'band') {
        row.band_from = parseWhole(draft.band_from, true);
        if (row.band_from === null) errors.band_from = 'Enter a whole number.';
        if (draft.band_to.trim() !== '') {
            row.band_to = parseWhole(draft.band_to, true);
            if (row.band_to === null) errors.band_to = 'Enter a whole number, or leave it empty for no upper end.';
            else if (row.band_from !== null && row.band_to <= row.band_from) errors.band_to = 'The band ends above where it starts.';
        }
        row.band_label = draft.band_label.trim() || null;
        if (row.band_label === null) errors.band_label = 'Name the band.';
        if (draft.value.trim() !== '') {
            row.value_bp = parseHundredths(draft.value, '%');
            if (row.value_bp === null) errors.value = 'Enter a percentage with at most two decimals, like 10 or 12.50.';
        } else {
            row.value_minor = draft.kept_minor;
        }
    } else {
        const parsed = parseRate(type, draft.value);
        if (parsed === null) {
            errors.value = type === 'flat' ? 'Enter an amount with at most two decimals, like 2,500.00.' : `Enter a rate with at most two decimals, like ${type === 'rate_pm' ? '2.25' : '10'}.`;
        } else if (type === 'flat') row.value_minor = parsed;
        else row.value_bp = parsed;
    }
    if (row.effective_from && row.effective_to && row.effective_to <= row.effective_from) errors.effective_to = 'The row ends after it starts.';
    return { row, errors };
}
